<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class OrderMapper
{
    /**
     * Tworzy (lub znajduje) klienta na podstawie danych z Erli.
     */
    public static function getOrCreateCustomer(array $orderData)
    {
        $email =
            ($orderData['buyer']['email'] ?? null) ?:
            ($orderData['user']['email'] ?? null);

        if (!$email) {
            $email = 'erli-' . time() . '@example.com';
        }

        $firstname =
            $orderData['buyer']['firstName'] ??
            ($orderData['user']['deliveryAddress']['firstName'] ?? 'ERLI');
        $lastname  =
            $orderData['buyer']['lastName'] ??
            ($orderData['user']['deliveryAddress']['lastName'] ?? 'Customer');

        $customerId = Customer::customerExists($email, true);
        if ($customerId) {
            return new Customer($customerId);
        }

        $customer = new Customer();
        $customer->email = $email;
        $customer->firstname = $firstname;
        $customer->lastname = $lastname;

        $plain = Tools::passwdGen();
        if (method_exists('Tools', 'hash')) {
            $customer->passwd = Tools::hash($plain);
        } elseif (method_exists('Tools', 'encrypt')) {
            $customer->passwd = Tools::encrypt($plain);
        } else {
            $customer->passwd = password_hash($plain, PASSWORD_DEFAULT);
        }

        $customer->id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
        $customer->id_shop = (int) Context::getContext()->shop->id;
        $customer->newsletter = 0;

        $customer->add();

        return $customer;
    }

    /**
     * Buduje adres (dostawa / faktura).
     */
    public static function createAddress(Customer $customer, array $addrData, $alias = 'ERLI')
    {
        $street  = $addrData['street']  ?? ($addrData['address'] ?? '');
        $zip     = $addrData['zipCode'] ?? ($addrData['zip'] ?? '');
        $country = $addrData['countryCode'] ?? ($addrData['country'] ?? 'PL');

        $address = new Address();
        $address->id_customer = (int) $customer->id;
        $address->alias = (string) $alias;
        $address->firstname = $addrData['firstName'] ?? $customer->firstname;
        $address->lastname = $addrData['lastName']  ?? $customer->lastname;
        $address->address1 = $street ?: ' ';
        $address->postcode = $zip;
        $address->city = $addrData['city']  ?? '';
        $address->phone = $addrData['phone'] ?? '';

        $idCountry = Country::getByIso(strtoupper($country));
        if (!$idCountry) {
            $idCountry = Country::getByIso('PL');
        }
        $address->id_country = (int) $idCountry;

        $address->add();

        return $address;
    }

    /**
     * Uzupełnia koszyk produktami na podstawie orderData z Erli.
     */
    public static function fillCartWithProducts(Cart $cart, array $orderData)
    {
        $items = $orderData['items'] ?? [];
        if (!is_array($items) || !$items) {
            return;
        }

        // Do mapowania wariantów po externalId (np. ps-12-345)
        if (!class_exists('ProductLinkRepository')) {
            $path = _PS_MODULE_DIR_ . 'erliintegration/classes/Repository/ProductLinkRepository.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }

        $linkRepo = class_exists('ProductLinkRepository') ? new ProductLinkRepository() : null;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            // w inboxie jest "externalId", w orders bywa "externalProductId"
            $externalId =
                $item['externalProductId'] ??
                ($item['externalId'] ?? null);

            if (!$externalId) {
                continue;
            }

            $quantity = (int) ($item['quantity'] ?? 1);
            if ($quantity <= 0) {
                $quantity = 1;
            }

            $externalId = trim((string) $externalId);

            // 1) Najpewniejsze: szukamy w naszej tabeli powiązań (erli_product_link)
            $idProduct = 0;
            $idAttribute = 0;

            if ($linkRepo) {
                try {
                    $row = $linkRepo->findByExternalId($externalId);
                    if (is_array($row) && !empty($row['id_product'])) {
                        $idProduct = (int) $row['id_product'];
                        $idAttribute = !empty($row['id_product_attribute']) ? (int) $row['id_product_attribute'] : 0;
                    }
                } catch (Throwable $e) {
                    
                }
            }

            // 2) Fallback: parsujemy externalId
            if ($idProduct <= 0) {
                // Najczęstszy format w naszej integracji: ps-<id_product>-<id_product_attribute>
                if (preg_match('/^ps-(\d+)(?:-(\d+))?$/', $externalId, $m)) {
                    $idProduct = (int) $m[1];
                    $idAttribute = isset($m[2]) ? (int) $m[2] : 0;
                }
                // Stary format: <id_product>-<id_product_attribute>
                elseif (preg_match('/^(\d+)(?:-(\d+))?$/', $externalId, $m)) {
                    $idProduct = (int) $m[1];
                    $idAttribute = isset($m[2]) ? (int) $m[2] : 0;
                }
            }

            if ($idProduct <= 0) {
                continue;
            }

            // Presta: dla produktu prostego id_product_attribute = 0
            $cart->updateQty($quantity, $idProduct, (int) $idAttribute);
        }
    }
}
