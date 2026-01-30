<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'erliintegration/classes/Api/ErliOrderApi.php';
require_once _PS_MODULE_DIR_ . 'erliintegration/classes/Mapper/OrderMapper.php';
require_once _PS_MODULE_DIR_ . 'erliintegration/classes/Repository/OrderLinkRepository.php';
require_once _PS_MODULE_DIR_ . 'erliintegration/classes/Repository/LogRepository.php';

class OrderSync
{
    public function processInbox($limit = 100, $maxBatches = 30)
    {
        $orderApi = new ErliOrderApi();
        $logRepo  = new LogRepository();
        $linkRepo = new OrderLinkRepository();

        $limit = (int) $limit;
        if ($limit < 1) $limit = 1;
        if ($limit > 100) $limit = 100;

        $maxBatches = (int) $maxBatches;
        if ($maxBatches < 1) $maxBatches = 1;

        $stats = [
            'batches' => 0,
            'events' => 0,
            'created' => 0,
            'ignored' => 0,
            'exceptions'=> 0,
            'acked' => 0,
        ];

        $lastBatchWasFull = false;

        for ($batch = 1; $batch <= $maxBatches; $batch++) {
            $stats['batches']++;
            $response = null;
            $tries = 0;

            while (true) {
                $tries++;
                $response = $orderApi->getInbox($limit);

                if (!is_array($response)) {
                    $logRepo->addLog(
                        'order_inbox_error',
                        '',
                        'Błędna odpowiedź getInbox (nie jest tablicą).',
                        print_r($response, true)
                    );
                    return $stats;
                }

                $code = (int) ($response['code'] ?? 0);

                if ($code === 429) {
                    if ($tries >= 5) {
                        $logRepo->addLog(
                            'order_inbox_error',
                            '',
                            'HTTP 429 z getInbox - przekroczono liczbę retry.',
                            $response['raw'] ?? ''
                        );
                        return $stats;
                    }
                    sleep(min(2 * $tries, 8));
                    continue;
                }

                if ($code < 200 || $code >= 300) {
                    $logRepo->addLog(
                        'order_inbox_error',
                        '',
                        'Błąd pobierania inbox: HTTP ' . $code,
                        $response['raw'] ?? ''
                    );
                    return $stats;
                }

                break;
            }

            $body = $response['body'] ?? null;

            if (!is_array($body) || empty($body)) {
                if ($batch === 1) {
                    $logRepo->addLog(
                        'order_inbox_empty',
                        '',
                        'Inbox pusty (brak nowych wiadomości).',
                        $response['raw'] ?? ''
                    );
                }
                return $stats;
            }

            $stats['events'] += count($body);
            $lastBatchWasFull = (count($body) >= $limit);

            $ackId = null;

            foreach ($body as $event) {
                if (!is_array($event)) {
                    continue;
                }

                if (isset($event['id'])) {
                    $id = $event['id'];
                    if ($ackId === null) {
                        $ackId = $id;
                    } else {
                        if (ctype_digit((string)$id) && ctype_digit((string)$ackId)) {
                            $ackId = ((int)$id > (int)$ackId) ? $id : $ackId;
                        } else {
                            $ackId = $id;
                        }
                    }
                }

                $type    = isset($event['type']) ? (string)$event['type'] : '';
                $payload = (isset($event['payload']) && is_array($event['payload'])) ? $event['payload'] : [];

                try {
                    if (in_array($type, ['orderCreated', 'ORDER_CREATED', 'newOrder'], true)) {
                        $before = $stats['created'];
                        $this->handleOrderCreated($orderApi, $linkRepo, $logRepo, $payload, $event);
                        $stats['created'] = $before + 1;
                        continue;
                    }

                    if (in_array($type, ['orderStatusChanged', 'orderSellerStatusChanged'], true)) {
                        $this->handleStatusChanged($orderApi, $linkRepo, $logRepo, $payload, $event, $type);
                        continue;
                    }

                    $stats['ignored']++;
                    $logRepo->addLog(
                        'order_event_ignored',
                        '',
                        'Pominięto event typu: ' . $type,
                        json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    );

                } catch (Throwable $e) {
                    $stats['exceptions']++;
                    $logRepo->addLog(
                        'order_event_exception',
                        '',
                        'Wyjątek podczas przetwarzania eventu: ' . $e->getMessage(),
                        json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    );
                }
            }

            if ($ackId !== null) {
                $triesAck = 0;

                while (true) {
                    $triesAck++;
                    $ackResp = $orderApi->ackInbox($ackId);

                    if (!is_array($ackResp)) {
                        $logRepo->addLog(
                            'order_ack_error',
                            (string)$ackId,
                            'Błędna odpowiedź ackInbox (nie jest tablicą).',
                            print_r($ackResp, true)
                        );
                        return $stats;
                    }

                    $ackCode = (int) ($ackResp['code'] ?? 0);

                    if ($ackCode === 429) {
                        if ($triesAck >= 5) {
                            $logRepo->addLog(
                                'order_ack_error',
                                (string)$ackId,
                                'HTTP 429 z ackInbox - przekroczono liczbę retry.',
                                $ackResp['raw'] ?? ''
                            );
                            return $stats;
                        }
                        sleep(min(2 * $triesAck, 8));
                        continue;
                    }

                    if ($ackCode < 200 || $ackCode >= 300) {
                        $logRepo->addLog(
                            'order_ack_error',
                            (string)$ackId,
                            'Błąd ACK inbox: HTTP ' . $ackCode,
                            $ackResp['raw'] ?? ''
                        );
                        return $stats;
                    }

                    $stats['acked']++;
                    break;
                }
            }

            if (count($body) < $limit) {
                return $stats;
            }

            usleep(120000);
        }

        if ($lastBatchWasFull) {
            $logRepo->addLog(
                'order_inbox_limit_reached',
                '',
                'Osiągnięto maxBatches=' . (int)$maxBatches . ' przy limit=' . (int)$limit . ' (możliwe że są jeszcze eventy).',
                ''
            );
        }

        return $stats;
    }

    protected function handleOrderCreated(
        ErliOrderApi $orderApi,
        OrderLinkRepository $linkRepo,
        LogRepository $logRepo,
        array $payload,
        array $event
    ) {
        $erliOrderId = $payload['id'] ?? null;
        if (!$erliOrderId) {
            $logRepo->addLog(
                'order_event_no_id',
                '',
                'Brak payload.id dla eventu orderCreated',
                json_encode($event)
            );
            return;
        }

        $existing = $linkRepo->findByErliOrderId($erliOrderId);
        if ($existing) {
            $logRepo->addLog(
                'order_skipped_existing',
                $erliOrderId,
                'Zamówienie już istnieje w PrestaShop – pomijam event orderCreated.',
                ''
            );
            return;
        }

        $orderResp = $orderApi->getOrder($erliOrderId);
        if (!is_array($orderResp)) {
            $logRepo->addLog(
                'order_fetch_error',
                $erliOrderId,
                'Błędna odpowiedź getOrder (nie jest tablicą).',
                print_r($orderResp, true)
            );
            return;
        }

        $orderCode = (int) ($orderResp['code'] ?? 0);
        if ($orderCode < 200 || $orderCode >= 300) {
            $logRepo->addLog(
                'order_fetch_error',
                $erliOrderId,
                'Błąd pobierania zamówienia: HTTP ' . $orderCode,
                $orderResp['raw'] ?? ''
            );
            return;
        }

        $orderData = isset($orderResp['body']) && is_array($orderResp['body'])
            ? $orderResp['body']
            : [];

        $idOrder = $this->createOrderFromErliData($orderData);
        if ($idOrder) {
            $status = isset($orderData['status']) ? (string) $orderData['status'] : '';
            $linkRepo->save($idOrder, $erliOrderId, $status);

            $logRepo->addLog(
                'order_created',
                (string) $idOrder,
                'Zamówienie utworzone z Erli (orderCreated).',
                json_encode($orderData)
            );
        } else {
            $logRepo->addLog(
                'order_create_error',
                $erliOrderId,
                'Nie udało się utworzyć zamówienia w PrestaShop.',
                json_encode($orderData)
            );
        }
    }

    protected function handleStatusChanged(
        ErliOrderApi $orderApi,
        OrderLinkRepository $linkRepo,
        LogRepository $logRepo,
        array $payload,
        array $event,
        $eventType
    ) {
        $erliOrderId = $payload['id'] ?? null;
        if (!$erliOrderId) {
            $logRepo->addLog(
                'order_event_no_id',
                '',
                'Brak payload.id dla eventu ' . $eventType,
                json_encode($event)
            );
            return;
        }

        $existing = $linkRepo->findByErliOrderId($erliOrderId);
        if ($existing) {
            try {
                $idOrder = (int) ($existing['id_order'] ?? $existing['idOrder'] ?? 0);
                if ($idOrder > 0) {
                    $order = new Order($idOrder);
                    $currentState = $order->getCurrentOrderState();
                    $isPaidState = !empty($currentState) && !empty($currentState->paid);

                    if (!$isPaidState) {
                        $orderResp = $orderApi->getOrder($erliOrderId);
                        if (is_array($orderResp)) {
                            $code = (int) ($orderResp['code'] ?? 0);
                            if ($code >= 200 && $code < 300) {
                                $orderData = isset($orderResp['body']) && is_array($orderResp['body'])
                                    ? $orderResp['body']
                                    : [];
                                $status = isset($orderData['status']) ? (string) $orderData['status'] : '';
                                if ($status !== '') {
                                    $this->updateOrderStatus($idOrder, $status);
                                    $logRepo->addLog(
                                        'order_status_updated_from_erli',
                                        (string) $idOrder,
                                        'Zaktualizowano status zamówienia z ERLI (tylko nieopłacone).',
                                        json_encode($orderData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                                    );
                                }
                            }
                        }
                    } else {
                        $logRepo->addLog(
                            'order_status_ignored_existing',
                            $erliOrderId,
                            'Otrzymano event ' . $eventType . ' dla istniejącego, opłaconego zamówienia – pomijam.',
                            json_encode($payload)
                        );
                    }
                }
            } catch (Throwable $e) {
                $logRepo->addLog(
                    'order_status_update_exception',
                    $erliOrderId,
                    'Wyjątek podczas aktualizacji statusu z ERLI: ' . $e->getMessage(),
                    json_encode($payload)
                );
            }
            return;
        }

        $orderResp = $orderApi->getOrder($erliOrderId);
        if (!is_array($orderResp)) {
            $logRepo->addLog(
                'order_fetch_error',
                $erliOrderId,
                'Błędna odpowiedź getOrder przy ' . $eventType . '.',
                print_r($orderResp, true)
            );
            return;
        }

        $orderCode = (int) ($orderResp['code'] ?? 0);
        if ($orderCode < 200 || $orderCode >= 300) {
            $logRepo->addLog(
                'order_fetch_error',
                $erliOrderId,
                'Błąd pobierania zamówienia przy ' . $eventType . ': HTTP ' . $orderCode,
                $orderResp['raw'] ?? ''
            );
            return;
        }

        $orderData = isset($orderResp['body']) && is_array($orderResp['body'])
            ? $orderResp['body']
            : [];

        $idOrder = $this->createOrderFromErliData($orderData);
        if (!$idOrder) {
            $logRepo->addLog(
                'order_create_error',
                $erliOrderId,
                'Nie udało się utworzyć zamówienia przy ' . $eventType . '.',
                json_encode($orderData)
            );
            return;
        }

        $status = isset($orderData['status']) ? (string) $orderData['status'] : '';
        $linkRepo->save($idOrder, $erliOrderId, $status);

        $logRepo->addLog(
            'order_created_from_status_event',
            (string) $idOrder,
            'Zamówienie utworzone z eventu ' . $eventType . ' (bo wcześniej nie istniało).',
            json_encode($orderData)
        );
    }

    protected function createOrderFromErliData(array $orderData)
    {
        $logRepo = new LogRepository();
        $context = Context::getContext();
        
        // ========== KROK 1: PRZEWOŹNIK (MUSI BYĆ PIERWSZY!) ==========
        $idCarrier = $this->getOrCreateCarrierFromErli($orderData);
        
        // Weryfikacja - czy przewoźnik rzeczywiście istnieje i jest aktywny
        $carrier = new Carrier($idCarrier);
        if (!Validate::isLoadedObject($carrier) || $carrier->deleted || !$carrier->active) {
            $logRepo->addLog(
                'carrier_invalid',
                (string) $idCarrier,
                'Przewoźnik ID ' . $idCarrier . ' nie istnieje lub jest nieaktywny - używam domyślnego',
                json_encode($orderData['delivery'] ?? [])
            );
            
            $idCarrier = (int) Configuration::get('PS_CARRIER_DEFAULT');
            if ($idCarrier <= 0) {
                $idCarrier = 1;
            }
            $carrier = new Carrier($idCarrier);
        }
        
        $logRepo->addLog(
            'carrier_selected_for_order',
            (string) $idCarrier,
            'Wybrany przewoźnik dla zamówienia: ID ' . $idCarrier . ' (' . $carrier->name . ')',
            json_encode($orderData['delivery'] ?? [])
        );
        
        // --- KROK 2: Klient ---
        $customer = OrderMapper::getOrCreateCustomer($orderData);
        
        // --- KROK 3: Adresy ---
        $shippingAddrData =
            $orderData['shippingAddress'] ??
            $orderData['deliveryAddress'] ??
            ($orderData['user']['deliveryAddress'] ?? []);

        $billingAddrData  =
            $orderData['billingAddress'] ??
            $orderData['invoiceAddress'] ??
            ($orderData['user']['invoiceAddress'] ?? $shippingAddrData);

        $deliveryAddress = OrderMapper::createAddress($customer, $shippingAddrData, 'ERLI Delivery');
        $invoiceAddress  = OrderMapper::createAddress($customer, $billingAddrData, 'ERLI Invoice');
        
        // --- KROK 4: Koszyk (z już przygotowanym przewoźnikiem) ---
        $cart = new Cart();
        $cart->id_lang             = (int) Configuration::get('PS_LANG_DEFAULT');
        $cart->id_currency         = (int) Configuration::get('PS_CURRENCY_DEFAULT');
        $cart->id_customer         = (int) $customer->id;
        $cart->id_address_delivery = (int) $deliveryAddress->id;
        $cart->id_address_invoice  = (int) $invoiceAddress->id;
        $cart->id_carrier          = (int) $idCarrier;
        $cart->secure_key          = (string) $customer->secure_key;
        $cart->add();
        
        // WAŻNE: Po zapisaniu koszyka, wymuś ponownie przewoźnika
        $cart->id_carrier = (int) $idCarrier;
        $cart->update();

        OrderMapper::fillCartWithProducts($cart, $orderData);

        /* ---------------------- MAPOWANIE STATUSU Z ERLI ----------------- */
        $erliStatus = isset($orderData['status']) ? (string) $orderData['status'] : '';
        $statusNorm = Tools::strtolower($erliStatus);

        $pendingStateConf   = (int) Configuration::get('ERLI_STATE_PENDING');
        $paidStateConf      = (int) Configuration::get('ERLI_STATE_PAID');
        $cancelledStateConf = (int) Configuration::get('ERLI_STATE_CANCELLED');
        $defaultStateConf   = (int) Configuration::get('ERLI_DEFAULT_ORDER_STATE');

        switch ($statusNorm) {
            case 'purchased':
            case 'paid':
            case 'completed':
                if ($paidStateConf > 0) {
                    $orderStatus = $paidStateConf;
                } else {
                    $orderStatus = (int) Configuration::get('PS_OS_PAYMENT');
                }
                break;

            case 'pending':
            case 'new':
            case 'awaiting_payment':
                if ($pendingStateConf > 0) {
                    $orderStatus = $pendingStateConf;
                } else {
                    $orderStatus = (int) Configuration::get('PS_OS_AWAITING_PAYMENT');
                }
                break;

            case 'cancelled':
            case 'canceled':
                if ($cancelledStateConf > 0) {
                    $orderStatus = $cancelledStateConf;
                } else {
                    $orderStatus = (int) Configuration::get('PS_OS_CANCELED');
                }
                break;

            default:
                if ($defaultStateConf > 0) {
                    $orderStatus = $defaultStateConf;
                } else {
                    $orderStatus = (int) Configuration::get('PS_OS_PAYMENT');
                }
        }

        /* =====================================================================
         *  KWOTY Z ERLI (grosze)
         * ================================================================== */

        $erliTotalCents = null;

        if (isset($orderData['summary']['total'])) {
            $erliTotalCents = (int) $orderData['summary']['total'];
        } elseif (isset($orderData['summary']['totalToPay'])) {
            $erliTotalCents = (int) $orderData['summary']['totalToPay'];
        } elseif (isset($orderData['totalPrice'])) {
            $erliTotalCents = (int) $orderData['totalPrice'];
        }

        $itemsTotalCents = null;
        $itemsTotalFound = false;

        if (!empty($orderData['items']) && is_array($orderData['items'])) {
            $sum = 0;
            foreach ($orderData['items'] as $item) {
                $qty = (int) ($item['quantity'] ?? 1);

                if (isset($item['totalPrice'])) {
                    $sum += (int) $item['totalPrice'];
                    $itemsTotalFound = true;
                } elseif (isset($item['price'])) {
                    $sum += (int) $item['price'] * $qty;
                    $itemsTotalFound = true;
                }
            }

            if ($itemsTotalFound) {
                $itemsTotalCents = $sum;
            }
        }

        $deliveryCostCents = null;
        if (isset($orderData['delivery']['price'])) {
            $deliveryCostCents = (int) $orderData['delivery']['price'];
        }

        if ($deliveryCostCents === null && $erliTotalCents !== null && $itemsTotalCents !== null) {
            $deliveryCostCents = max(0, $erliTotalCents - $itemsTotalCents);
        }

        $erliTotal     = $erliTotalCents !== null ? $erliTotalCents / 100.0 : null;
        $itemsTotal    = $itemsTotalCents !== null ? $itemsTotalCents / 100.0 : null;
        $shippingTotal = $deliveryCostCents !== null ? $deliveryCostCents / 100.0 : null;

        $cartTotal = (float) $cart->getOrderTotal(true, Cart::BOTH);

        if ($erliTotal !== null && abs($erliTotal - $cartTotal) > 0.01) {
            $logRepo->addLog(
                'order_total_mismatch',
                (string) ($orderData['id'] ?? ($orderData['orderId'] ?? '')),
                'Kwota z ERLI (' . $erliTotal . ') różni się od sumy koszyka (' . $cartTotal . ').',
                json_encode($orderData)
            );
        }

        $validateAmount = $cartTotal;
        $paymentMethodName = 'Erli Payment';

        $extraVars = [
            'transaction_id' => $orderData['id'] ?? ($orderData['orderId'] ?? ''),
        ];

        $module = Module::getInstanceByName('erliintegration');

        if (!($module instanceof PaymentModule)) {
            $logRepo->addLog(
                'order_create_error',
                '',
                'Moduł erliintegration nie jest PaymentModule.',
                ''
            );
            return null;
        }

        $result = $module->validateOrder(
            (int) $cart->id,
            $orderStatus,
            $validateAmount,
            $paymentMethodName,
            null,
            $extraVars,
            (int) $cart->id_currency,
            false,
            $customer->secure_key,
            $context->shop
        );

        if (!$result || !$module->currentOrder) {
            $logRepo->addLog(
                'order_create_error',
                '',
                'validateOrder() zwróciło false.',
                json_encode($orderData)
            );
            return null;
        }

        $idOrder = (int) $module->currentOrder;

        $finalPaid = $erliTotal !== null ? $erliTotal : $cartTotal;

        $order = new Order($idOrder);

        $order->total_paid          = $finalPaid;
        $order->total_paid_tax_incl = $finalPaid;
        $order->total_paid_tax_excl = $finalPaid;
        $order->total_paid_real     = $finalPaid;

        if ($itemsTotal !== null) {
            $order->total_products    = $itemsTotal;
            $order->total_products_wt = $itemsTotal;
        }

        if ($shippingTotal !== null) {
            $order->total_shipping          = $shippingTotal;
            $order->total_shipping_tax_incl = $shippingTotal;
            $order->total_shipping_tax_excl = $shippingTotal;
        }
        
        // WYMUSZENIE PRZEWOŹNIKA PRZED UPDATE
        $order->id_carrier = (int) $idCarrier;
        $order->update();

        // ========== WYMUSZENIE PRZEWOŹNIKA W BAZIE DANYCH ==========
        try {
            // Aktualizacja tabeli ps_orders
            Db::getInstance()->execute(
                'UPDATE `' . _DB_PREFIX_ . 'orders` SET id_carrier = ' . (int) $idCarrier . ' WHERE id_order = ' . (int) $idOrder
            );

            // Aktualizacja tabeli ps_order_carrier (KRYTYCZNE!)
            $result = Db::getInstance()->executeS(
                'SELECT id_order_carrier FROM `' . _DB_PREFIX_ . 'order_carrier` WHERE id_order = ' . (int) $idOrder . ' ORDER BY id_order_carrier DESC LIMIT 1'
            );
            $orderCarrierId = !empty($result) ? (int) $result[0]['id_order_carrier'] : 0;

            if ($orderCarrierId > 0) {
                // Aktualizuj przewoźnika i koszt wysyłki w ps_order_carrier
                $shippingCost = $shippingTotal !== null ? (float) $shippingTotal : 0.0;

                Db::getInstance()->execute(
                    'UPDATE `' . _DB_PREFIX_ . 'order_carrier`
                     SET id_carrier = ' . (int) $idCarrier . ',
                         shipping_cost_tax_excl = ' . (float) $shippingCost . ',
                         shipping_cost_tax_incl = ' . (float) $shippingCost . '
                     WHERE id_order_carrier = ' . (int) $orderCarrierId
                );

                $logRepo->addLog(
                    'carrier_order_carrier_updated',
                    (string) $idOrder,
                    'Zaktualizowano ps_order_carrier: id_order_carrier=' . $orderCarrierId . ', id_carrier=' . $idCarrier . ', shipping_cost=' . $shippingCost,
                    ''
                );
            } else {
                $logRepo->addLog(
                    'carrier_order_carrier_not_found',
                    (string) $idOrder,
                    'Nie znaleziono wpisu ps_order_carrier dla zamówienia',
                    ''
                );
            }

            $verifyResult = Db::getInstance()->executeS(
                'SELECT id_carrier FROM `' . _DB_PREFIX_ . 'orders` WHERE id_order = ' . (int) $idOrder
            );
            $savedCarrierId = !empty($verifyResult) ? (int) $verifyResult[0]['id_carrier'] : 0;

            $logRepo->addLog(
                'carrier_verification',
                (string) $idOrder,
                'Przewoźnik w zamówieniu: oczekiwany=' . $idCarrier . ', zapisany=' . $savedCarrierId,
                ''
            );
        } catch (Throwable $e) {
            $logRepo->addLog(
                'carrier_force_error',
                (string) $idOrder,
                'Błąd wymuszania przewoźnika: ' . $e->getMessage(),
                ''
            );
        }

        try {
            $payments = $order->getOrderPaymentCollection();
            if ($payments && $payments->count()) {
                foreach ($payments as $payment) {
                    if (!Validate::isLoadedObject($payment)) {
                        continue;
                    }
                    $payment->amount = $finalPaid;
                    $payment->update();
                    break;
                }
            }
        } catch (Throwable $e) {
            $logRepo->addLog(
                'order_payment_update_error',
                (string) $idOrder,
                'Błąd przy aktualizacji ps_order_payment: ' . $e->getMessage(),
                ''
            );
        }

        // Aktualizacja faktury (ps_order_invoice)
        try {
            $invoiceCollection = $order->getInvoicesCollection();
            if ($invoiceCollection && $invoiceCollection->count()) {
                foreach ($invoiceCollection as $invoice) {
                    if (!Validate::isLoadedObject($invoice)) {
                        continue;
                    }

                    $invoice->total_paid_tax_incl = $finalPaid;
                    $invoice->total_paid_tax_excl = $finalPaid;

                    if ($itemsTotal !== null) {
                        $invoice->total_products = $itemsTotal;
                        $invoice->total_products_wt = $itemsTotal;
                    }

                    if ($shippingTotal !== null) {
                        $invoice->total_shipping_tax_incl = $shippingTotal;
                        $invoice->total_shipping_tax_excl = $shippingTotal;
                    }

                    $invoice->update();

                    $logRepo->addLog(
                        'invoice_updated',
                        (string) $idOrder,
                        'Zaktualizowano fakturę: total=' . $finalPaid . ', products=' . $itemsTotal . ', shipping=' . $shippingTotal,
                        ''
                    );
                    break;
                }
            }
        } catch (Throwable $e) {
            $logRepo->addLog(
                'invoice_update_error',
                (string) $idOrder,
                'Błąd przy aktualizacji faktury: ' . $e->getMessage(),
                ''
            );
        }

        if ($order->current_state != $orderStatus) {
            $history = new OrderHistory();
            $history->id_order = (int) $order->id;
            $history->changeIdOrderState($orderStatus, (int) $order->id);
            $history->add();
        }

        return $idOrder;
    }

    protected function getOrCreateCarrierFromErli(array $orderData)
    {
        $logRepo = new LogRepository();
        
        $delivery = $orderData['delivery'] ?? [];
        
        $erliTypeId = isset($delivery['typeId']) ? trim((string) $delivery['typeId']) : '';
        $erliName   = isset($delivery['name']) ? trim((string) $delivery['name']) : '';
        $erliPrice  = isset($delivery['price']) ? (int) $delivery['price'] : 0;
        
        $logRepo->addLog(
            'carrier_search_start',
            '',
            'Szukam przewoźnika ERLI',
            json_encode(['typeId' => $erliTypeId, 'name' => $erliName, 'price' => $erliPrice])
        );
        
        $defaultCarrierId = (int) Configuration::get('ERLI_DEFAULT_CARRIER');
        if ($defaultCarrierId <= 0) {
            $defaultCarrierId = (int) Configuration::get('PS_CARRIER_DEFAULT');
        }
        
        if ($erliTypeId === '' && $erliName === '') {
            return $defaultCarrierId;
        }
        
        // Sprawdź mapowanie
        $sql = 'SELECT id_carrier 
                FROM `' . _DB_PREFIX_ . 'erli_shipping_map` 
                WHERE erli_tag = "' . pSQL($erliTypeId) . '"';
        
        $existingCarrierId = (int) Db::getInstance()->getValue($sql);
        
        if ($existingCarrierId > 0) {
            $carrier = new Carrier($existingCarrierId);
            if (Validate::isLoadedObject($carrier) && !$carrier->deleted && $carrier->active) {
                $logRepo->addLog(
                    'carrier_found_in_map',
                    (string) $existingCarrierId,
                    'Znaleziono w mapowaniu: ' . $carrier->name,
                    ''
                );
                return $existingCarrierId;
            }
        }
        
        // Szukaj po nazwie
        if ($erliName !== '') {
            $carriers = Carrier::getCarriers(
                (int) Configuration::get('PS_LANG_DEFAULT'),
                false,
                false,
                false,
                null,
                Carrier::ALL_CARRIERS
            );
            
            foreach ($carriers as $c) {
                $carrierName = isset($c['name']) ? trim((string) $c['name']) : '';
                
                if (Tools::strtolower($carrierName) === Tools::strtolower($erliName)) {
                    $foundCarrierId = (int) $c['id_carrier'];
                    
                    $carrier = new Carrier($foundCarrierId);
                    if (!$carrier->deleted && $carrier->active) {
                        $this->saveCarrierMapping($foundCarrierId, $erliTypeId, $erliName);
                        
                        $logRepo->addLog(
                            'carrier_found_by_name',
                            (string) $foundCarrierId,
                            'Znaleziono po nazwie: ' . $erliName,
                            ''
                        );
                        
                        return $foundCarrierId;
                    }
                }
            }
        }
        
        // Twórz nowego
        try {
            $newCarrier = new Carrier();
            
            $carrierName = $erliName !== '' ? $erliName : 'ERLI ' . $erliTypeId;
            
            $logRepo->addLog(
                'carrier_creating',
                '',
                'Tworzę nowego: ' . $carrierName,
                ''
            );
            
            $newCarrier->name = $carrierName;
            $newCarrier->active = 1;
            $newCarrier->deleted = 0;
            $newCarrier->shipping_handling = 0;
            $newCarrier->range_behavior = 0;
            $newCarrier->is_module = 0;
            $newCarrier->shipping_external = 0;
            $newCarrier->external_module_name = '';
            $newCarrier->need_range = 1;
            
            $newCarrier->delay = [];
            foreach (Language::getLanguages(true) as $lang) {
                $newCarrier->delay[(int) $lang['id_lang']] = '2-4 dni robocze';
            }
            
            $newCarrier->max_width = 0;
            $newCarrier->max_height = 0;
            $newCarrier->max_depth = 0;
            $newCarrier->max_weight = 30;
            $newCarrier->grade = 1;
            
            if (!$newCarrier->add()) {
                throw new Exception('add() zwróciło false');
            }
            
            $newCarrierId = (int) $newCarrier->id;
            
            $groups = Group::getGroups((int) Configuration::get('PS_LANG_DEFAULT'));
            $groupIds = [];
            foreach ($groups as $g) {
                $groupIds[] = (int) $g['id_group'];
            }
            $newCarrier->setGroups($groupIds);
            
            $zones = Zone::getZones();
            foreach ($zones as $z) {
                $newCarrier->addZone((int) $z['id_zone']);
            }
            
            $priceInZl = $erliPrice > 0 ? ($erliPrice / 100.0) : 0;
            
            $rangePrice = new RangePrice();
            $rangePrice->id_carrier = $newCarrierId;
            $rangePrice->delimiter1 = 0;
            $rangePrice->delimiter2 = 10000;
            
            if (!$rangePrice->add()) {
                throw new Exception('RangePrice add() failed');
            }
            
            foreach ($zones as $z) {
                Db::getInstance()->insert('delivery', [
                    'id_carrier' => (int) $newCarrierId,
                    'id_range_price' => (int) $rangePrice->id,
                    'id_range_weight' => null,
                    'id_zone' => (int) $z['id_zone'],
                    'price' => (float) $priceInZl,
                ]);
            }
            
            $this->saveCarrierMapping($newCarrierId, $erliTypeId, $carrierName);
            
            $logRepo->addLog(
                'carrier_created',
                (string) $newCarrierId,
                'Utworzono: ' . $carrierName . ' (cena: ' . $priceInZl . ' zł)',
                json_encode($delivery)
            );
            
            return $newCarrierId;
            
        } catch (Throwable $e) {
            $logRepo->addLog(
                'carrier_create_error',
                '',
                'Błąd: ' . $e->getMessage(),
                ''
            );
            
            return $defaultCarrierId;
        }
    }
    
    protected function saveCarrierMapping($idCarrier, $erliTypeId, $erliName)
    {
        $idCarrier = (int) $idCarrier;
        $erliTypeId = pSQL(trim((string) $erliTypeId));
        $erliName = pSQL(trim((string) $erliName));
        
        if ($idCarrier <= 0 || $erliTypeId === '') {
            return;
        }
        
        $exists = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) 
             FROM `' . _DB_PREFIX_ . 'erli_shipping_map` 
             WHERE id_carrier = ' . $idCarrier
        );
        
        if ($exists > 0) {
            Db::getInstance()->update(
                'erli_shipping_map',
                [
                    'erli_tag' => $erliTypeId,
                    'erli_name' => $erliName,
                ],
                'id_carrier = ' . $idCarrier
            );
        } else {
            Db::getInstance()->insert(
                'erli_shipping_map',
                [
                    'id_carrier' => $idCarrier,
                    'erli_tag' => $erliTypeId,
                    'erli_name' => $erliName,
                ]
            );
        }
    }

    protected function updateOrderStatus($idOrder, $erliStatus)
    {
        $erliStatus = (string) $erliStatus;
        if ($erliStatus === '') {
            return;
        }

        $map = [
            'pending'   => (int) Configuration::get('ERLI_STATE_PENDING'),
            'purchased' => (int) Configuration::get('ERLI_STATE_PAID'),
            'cancelled' => (int) Configuration::get('ERLI_STATE_CANCELLED'),
        ];

        if (!isset($map[$erliStatus])) {
            return;
        }

        $newState = (int) $map[$erliStatus];
        if ($newState <= 0) {
            return;
        }

        $history           = new OrderHistory();
        $history->id_order = (int) $idOrder;
        $history->changeIdOrderState($newState, $idOrder);
        $history->add();
    }
}