{*
 * Dashboard integracji Erli
 * Oczekiwane zmienne:
 *  - $total_products
 *  - $synced_products
 *  - $total_orders
 *  - $erli_orders
 *  - $last_sync
 *  - $last_logs (tablica)
 *}

<div class="panel">
    <a href = "{$link_configure}" class="btn btn-default">
    <i class="icon-arrow-left"></i>
    {l s='Wstecz' mod='erliintegration'}
    </a>
    <h3><i class="icon-dashboard"></i> {l s='Dashboard Erli' mod='erliintegration'}</h3>

    <div class="row">
        <div class="col-lg-3">
            <div class="panel">
                <h4>{l s='Produkty w sklepie' mod='erliintegration'}</h4>
                <p class="lead">{$total_products|intval}</p>
            </div>
        </div>
        <div class="col-lg-3">
            <div class="panel">
                <h4>{l s='Produkty powiązane z Erli' mod='erliintegration'}</h4>
                <p class="lead">{$synced_products|intval}</p>
            </div>
        </div>
        <div class="col-lg-3">
            <div class="panel">
                <h4>{l s='Zamówienia w sklepie' mod='erliintegration'}</h4>
                <p class="lead">{$total_orders|intval}</p>
            </div>
        </div>
        <div class="col-lg-3">
            <div class="panel">
                <h4>{l s='Zamówienia z Erli' mod='erliintegration'}</h4>
                <p class="lead">{$erli_orders|intval}</p>
            </div>
        </div>
    </div>

    <p class="text-muted">
        {l s='Ostatnia udana synchronizacja:' mod='erliintegration'}
        {if isset($last_sync) && $last_sync}
            {$last_sync|escape:'html':'UTF-8'}
        {else}
            {l s='brak danych' mod='erliintegration'}
        {/if}
    </p>
</div>

<div class="panel">
    <h3><i class="icon-bug"></i> {l s='Ostatnie logi integracji' mod='erliintegration'}</h3>

    <table class="table">
        <thead>
            <tr>
                <th>{l s='Data' mod='erliintegration'}</th>
                <th>{l s='Typ' mod='erliintegration'}</th>
                <th>{l s='Referencja' mod='erliintegration'}</th>
                <th>{l s='Komunikat' mod='erliintegration'}</th>
            </tr>
        </thead>
        <tbody>
        {if isset($last_logs) && $last_logs && count($last_logs)}
            {foreach from=$last_logs item=log}
                <tr>
                    <td>{$log.created_at|escape:'html':'UTF-8'}</td>
                    <td>{$log.type|escape:'html':'UTF-8'}</td>
                    <td>{$log.reference_id|escape:'html':'UTF-8'}</td>
                    <td>{$log.message|escape:'html':'UTF-8'}</td>
                </tr>
            {/foreach}
        {else}
            <tr>
                <td colspan="4">
                    {l s='Brak logów do wyświetlenia.' mod='erliintegration'}
                </td>
            </tr>
        {/if}
        </tbody>
    </table>
</div>
