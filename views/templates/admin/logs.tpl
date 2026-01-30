<div class="panel">
    <a href = "{$link_configure}" class="btn btn-default">
    <i class="icon-arrow-left"></i>
    {l s='Wstecz' mod='erliintegration'}
    </a>
    <h3><i class="icon-bug"></i> {l s='Logi integracji Erli' mod='erliintegration'}</h3>

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
        {if isset($logs) && $logs && count($logs)}
            {foreach from=$logs item=log}
                <tr>
                    <td>{$log.created_at|escape:'html':'UTF-8'}</td>
                    <td>{$log.type|escape:'html':'UTF-8'}</td>
                    <td>{$log.reference_id|escape:'html':'UTF-8'}</td>
                    <td>
                        <span title="{$log.payload|escape:'html':'UTF-8'}">
                            {$log.message|escape:'html':'UTF-8'}
                        </span>
                    </td>
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
