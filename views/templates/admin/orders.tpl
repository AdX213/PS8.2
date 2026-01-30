<div class="panel">
  <a href="{$link_configure|escape:'html':'UTF-8'}" class="btn btn-default">
    <i class="icon-arrow-left"></i>
    {l s='Wstecz' mod='erliintegration'}
  </a>

  <h3><i class="icon-shopping-cart"></i> {l s='Import zamówień z Erli' mod='erliintegration'}</h3>

  {$output nofilter}

  <form method="post" action="{$link_orders|escape:'html':'UTF-8'}">
    <button type="submit" name="submitErliImportOrders" class="btn btn-primary">
      <i class="icon-refresh"></i>
      {l s='Pobierz zamówienia z Erli teraz' mod='erliintegration'}
    </button>
  </form>

  <p class="help-block">
    {l s='Zalecane jest skonfigurowanie CRON, aby importować zamówienia automatycznie co kilka minut.' mod='erliintegration'}
  </p>
</div>

<div class="panel">
  <h3><i class="icon-list"></i> {l s='Zamówienia powiązane z Erli' mod='erliintegration'}</h3>

  <table class="table">
    <thead>
      <tr>
        <th>{l s='ID zamówienia' mod='erliintegration'}</th>
        <th>{l s='ID Erli' mod='erliintegration'}</th>
        <th>{l s='Status (Erli)' mod='erliintegration'}</th>
        <th>{l s='Data powiązania' mod='erliintegration'}</th>
        <th>{l s='Podgląd' mod='erliintegration'}</th>
      </tr>
    </thead>
    <tbody>
      {if isset($orders) && $orders && count($orders)}
        {foreach from=$orders item=o}
          <tr>
            <td>{$o.id_order|intval}</td>
            <td>{$o.erli_order_id|escape:'html':'UTF-8'}</td>
            <td>{$o.last_status|escape:'html':'UTF-8'}</td>
            <td>{$o.created_at|escape:'html':'UTF-8'}</td>
            <td>
              <a href="index.php?controller=AdminOrders&amp;id_order={$o.id_order|intval}&amp;vieworder&amp;token={$admin_orders_token|escape:'html':'UTF-8'}"
                 class="btn btn-default btn-sm">
                <i class="icon-search"></i> {l s='Zobacz' mod='erliintegration'}
              </a>
            </td>
          </tr>
        {/foreach}
      {else}
        <tr>
          <td colspan="5">{l s='Brak powiązanych zamówień.' mod='erliintegration'}</td>
        </tr>
      {/if}
    </tbody>
  </table>
</div>
