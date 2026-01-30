<div class="panel">
  <a href="{$link_configure|escape:'html':'UTF-8'}" class="btn btn-default">
    <i class="icon-arrow-left"></i>
    {l s='Wstecz' mod='erliintegration'}
  </a>

  <h3><i class="icon-refresh"></i> {l s='Synchronizacja produktów z Erli.pl' mod='erliintegration'}</h3>

  {$output nofilter}

  <form method="post" action="{$link_products|escape:'html':'UTF-8'}" style="margin-bottom:15px;">
    <div class="form-group">
      <label>{l s='ID produktu PrestaShop' mod='erliintegration'}:</label>
      <input type="number" name="ERLI_PRODUCT_ID" class="form-control" />
    </div>

    <button type="submit" name="submitSyncProduct" class="btn btn-primary">
      <i class="icon-upload"></i>
      {l s='Wyślij produkt do Erli' mod='erliintegration'}
    </button>
  </form>

  <form method="post" action="{$link_products|escape:'html':'UTF-8'}">
    <button type="submit" name="submitErliSyncAllProducts" class="btn btn-primary">
      <i class="icon-cloud-upload"></i>
      {l s='Wyślij wszystkie produkty do Erli' mod='erliintegration'}
    </button>
  </form>
</div>

<div class="panel">
  <h3><i class="icon-list"></i> {l s='Lista produktów PrestaShop' mod='erliintegration'}</h3>

  <table class="table table-striped">
    <thead>
      <tr>
        <th>{l s='ID' mod='erliintegration'}</th>
        <th>{l s='Zdjęcie' mod='erliintegration'}</th>
        <th>{l s='Nazwa' mod='erliintegration'}</th>
        <th>{l s='Cena' mod='erliintegration'}</th>
        <th>{l s='Stan' mod='erliintegration'}</th>
        <th>{l s='Akcja' mod='erliintegration'}</th>
      </tr>
    </thead>

    <tbody>
      {if isset($products) && $products && count($products)}
        {foreach from=$products item=p}
          <tr>
            <td>{$p.id_product|intval}</td>
            <td>
              {if isset($p.image) && $p.image}
                <img src="{$p.image|escape:'htmlall':'UTF-8'}" alt="" width="50" height="50" />
              {/if}
            </td>
            <td>{$p.name|escape:'htmlall':'UTF-8'}</td>
            <td>{$p.price}</td>
            <td>{$p.quantity}</td>
            <td>
              <form method="post" action="{$link_products|escape:'html':'UTF-8'}" style="margin:0;">
                <input type="hidden" name="ERLI_PRODUCT_ID" value="{$p.id_product|intval}" />
                <button type="submit" class="btn btn-primary" name="submitSyncProduct">
                  <i class="icon-refresh"></i>
                  {l s='Synchronizuj' mod='erliintegration'}
                </button>
              </form>
            </td>
          </tr>
        {/foreach}
      {else}
        <tr>
          <td colspan="6">{l s='Brak produktów.' mod='erliintegration'}</td>
        </tr>
      {/if}
    </tbody>
  </table>
</div>
