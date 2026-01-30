{*
 * Główna strona konfiguracji modułu Erli
 * Oczekiwane zmienne (przykładowo):
 *  - $form_html        – kod formularza HelperForm (API key, ustawienia)
 *  - $cron_url         – URL do CRON
 *  - $link_dashboard   – link do dashboardu
 *  - $link_products    – link do widoku produktów
 *  - $link_orders      – link do widoku zamówień
 *  - $link_mapping     – link do mapowania
 *  - $link_logs        – link do logów
 *}

<div class="panel">
    <h3>
        <i class="icon-cogs"></i>
        {l s='Konfiguracja integracji Erli.pl' mod='erliintegration'}
    </h3>

    {if isset($form_html)}
        {$form_html nofilter}
    {else}
        <p class="text-muted">
            {l s='Formularz konfiguracji nie został przekazany do szablonu.' mod='erliintegration'}
        </p>
    {/if}
</div>

<div class="row">
    <div class="col-lg-3">
        <div class="panel">
            <h3><i class="icon-dashboard"></i> {l s='Dashboard' mod='erliintegration'}</h3>
            <p>{l s='Podsumowanie produktów, zamówień i ostatnich synchronizacji.' mod='erliintegration'}</p>
            {if isset($link_dashboard)}
                <a href="{$link_dashboard|escape:'html':'UTF-8'}" class="btn btn-default">
                    <i class="icon-external-link"></i>
                    {l s='Przejdź' mod='erliintegration'}
                </a>
            {/if}
        </div>
    </div>

    <div class="col-lg-3">
        <div class="panel">
            <h3><i class="icon-cube"></i> {l s='Produkty' mod='erliintegration'}</h3>
            <p>{l s='Ręczna synchronizacja produktów i podgląd powiązań.' mod='erliintegration'}</p>
            {if isset($link_products)}
                <a href="{$link_products|escape:'html':'UTF-8'}" class="btn btn-default">
                    <i class="icon-external-link"></i>
                    {l s='Przejdź' mod='erliintegration'}
                </a>
            {/if}
        </div>
    </div>

    <div class="col-lg-3">
        <div class="panel">
            <h3><i class="icon-shopping-cart"></i> {l s='Zamówienia' mod='erliintegration'}</h3>
            <p>{l s='Import zamówień z Erli oraz ich statusy.' mod='erliintegration'}</p>
            {if isset($link_orders)}
                <a href="{$link_orders|escape:'html':'UTF-8'}" class="btn btn-default">
                    <i class="icon-external-link"></i>
                    {l s='Przejdź' mod='erliintegration'}
                </a>
            {/if}
        </div>
    </div>
    
{* Mapowanie kategori TO DO *}
    <div class="col-lg-3">
        <div class="panel">
            <h3><i class="icon-random"></i> {l s='Mapowanie' mod='erliintegration'}</h3>
            <p>{l s='Mapowanie kategorii i przewoźników na Erli.' mod='erliintegration'}</p>
            {if isset($link_mapping)}
                <a href="{$link_mapping|escape:'html':'UTF-8'}" class="btn btn-default">
                    <i class="icon-external-link"></i>
                    {l s='Przejdź' mod='erliintegration'}
                </a>
            {/if}
        </div>
    </div>
</div>


<div class="panel">
    <h3><i class="icon-file-text-o"></i> {l s='Logi integracji' mod='erliintegration'}</h3>
    <p>{l s='Podgląd ostatnich komunikatów z synchronizacji produktów i zamówień.' mod='erliintegration'}</p>

    {if isset($link_logs)}
        <a href="{$link_logs|escape:'html':'UTF-8'}" class="btn btn-default">
            <i class="icon-external-link"></i>
            {l s='Przejdź do logów' mod='erliintegration'}
        </a>
    {/if}
</div>

{if isset($cron_url)}
    <div class="panel">
        <h3><i class="icon-clock-o"></i> {l s='CRON – automatyczna synchronizacja zamówień' mod='erliintegration'}</h3>
        <p>{l s='Skonfiguruj zadanie CRON na serwerze, aby cyklicznie wywoływać ten URL:' mod='erliintegration'}</p>
        <pre style="user-select:all;">{$cron_url|escape:'html':'UTF-8'}</pre>
        <p>{l s='Przykład (co 5 minut, cron Linux):' mod='erliintegration'}</p>
        <pre>*/5 * * * * curl -s "{$cron_url|escape:'html':'UTF-8'}" &gt;/dev/null 2&gt;&1</pre>
    </div>
{/if}
