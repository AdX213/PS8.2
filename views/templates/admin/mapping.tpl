<div class="panel">
  <a href="{$link_configure|escape:'html':'UTF-8'}" class="btn btn-default">
    <i class="icon-arrow-left"></i> {l s='Wstecz' mod='erliintegration'}
  </a>

  <h3 style="margin-top:10px;">
    <i class="icon-sitemap"></i> {l s='Mapowanie kategorii na Erli' mod='erliintegration'}
  </h3>

  <div style="margin:10px 0;">
    <button type="button" id="erli-fetch-cats" class="btn btn-default">
      <i class="icon-download"></i> {l s='Pobierz kategorie z ERLI' mod='erliintegration'}
    </button>

    <span id="erli-cats-status" style="margin-left:12px; color:#666;"></span>
  </div>

  <form method="post">
    <table class="table" id="erli-map-table">
      <thead>
        <tr>
          <th>{l s='ID kategorii' mod='erliintegration'}</th>
          <th>{l s='Nazwa kategorii (Presta)' mod='erliintegration'}</th>
          <th style="width:220px;">{l s='ID kategorii Erli' mod='erliintegration'}</th>
          <th>{l s='Wybierz kategorię z Erli (wyszukaj)' mod='erliintegration'}</th>
        </tr>
      </thead>

      <tbody>
      {foreach from=$category_rows item=row}
        <tr data-id-category="{$row.id_category|intval}">
          <td>{$row.id_category|intval}</td>
          <td class="presta-name">{$row.category_name|escape:'html':'UTF-8'}</td>

          <td>
            <input type="text"
                   name="category[{$row.id_category|intval}][erli_category_id]"
                   value="{$row.erli_category_id|escape:'html':'UTF-8'}"
                   class="form-control erli-cat-id"
                   placeholder="{l s='np. 12345' mod='erliintegration'}" />
          </td>

          <td>
            <div class="erli-picker">
              <input type="text"
                     class="form-control erli-cat-search"
                     placeholder="{l s='Wpisz min. 2 znaki...' mod='erliintegration'}"
                     autocomplete="off" />

              <input type="hidden"
                     name="category[{$row.id_category|intval}][erli_category_name]"
                     value="{$row.erli_category_name|escape:'html':'UTF-8'}"
                     class="erli-cat-name" />

              <div class="erli-picked" style="margin-top:6px; color:#333;">
                {if $row.erli_category_name}
                  <small>
                    <b>{l s='Aktualnie:' mod='erliintegration'}</b>
                    {$row.erli_category_name|escape:'html':'UTF-8'}
                  </small>
                {else}
                  <small style="color:#888;">{l s='Brak przypisania' mod='erliintegration'}</small>
                {/if}
              </div>

              <div class="erli-results" style="display:none;"></div>
            </div>
          </td>
        </tr>
      {/foreach}
      </tbody>
    </table>

    <button type="submit" name="submitErliSaveCategoryMapping" class="btn btn-primary">
      <i class="icon-save"></i> {l s='Zapisz mapowanie kategorii' mod='erliintegration'}
    </button>

      {*  MAPOWANIE METOD DOSTAWY ERLI – POD TABELĄ KATEGORII      *}
      <hr />

      <h3>{l s='Mapowanie dostaw ERLI' mod='erliintegration'}</h3>

      {if !$erli_price_lists}
        <div class="alert alert-warning">
          {l s='Nie udało się pobrać listy cenników dostaw z ERLI. Sprawdź klucz API.' mod='erliintegration'}
        </div>
      {else}
        <table class="table">
          <thead>
            <tr>
              <th>{l s='Przewoźnik (Presta)' mod='erliintegration'}</th>
              <th>{l s='Cennik ERLI (priceList)' mod='erliintegration'}</th>
            </tr>
          </thead>
          <tbody>
            {foreach from=$carriers item=carrier}
              {assign var=id_carrier value=$carrier.id_carrier}
              <tr>
                <td>{$carrier.name|escape:'html':'UTF-8'}</td>
                <td>
                  <select name="shipping[{$id_carrier}]">
                    <option value="">
                      -- {l s='Brak mapowania / użyj domyślnego cennika ERLI' mod='erliintegration'} --
                    </option>
                    {foreach from=$erli_price_lists item=tag}
                      <option value="{$tag|escape:'html':'UTF-8'}"
                        {if isset($shipping_map[$id_carrier]) && $shipping_map[$id_carrier].erli_tag == $tag}selected="selected"{/if}>
                        {$tag|escape:'html':'UTF-8'}
                      </option>
                    {/foreach}
                  </select>
                </td>
              </tr>
            {/foreach}
          </tbody>
        </table>

        <button type="submit" name="submitErliSaveShippingMapping" class="btn btn-primary">
          {l s='Zapisz mapowanie dostaw' mod='erliintegration'}
        </button>
      {/if}

  </form>
</div>

<style>
  .erli-picker { position: relative; }

  .erli-results{
    position:absolute;
    z-index:9999;
    left:0; right:0;
    top:38px;
    background:#fff;
    border:1px solid #ddd;
    max-height:260px;
    overflow:auto;
    box-shadow:0 4px 14px rgba(0,0,0,.08);
    padding:4px 0;
  }

  .erli-item{
    padding:8px 10px;
    cursor:pointer;
    border-bottom:1px solid #f2f2f2;
  }
  .erli-item:hover{ background:#f6f6f6; }
  .erli-item small{
    color:#777;
    display:block;
    margin-top:2px;
  }
</style>

<script>
(function () {
  var fetchUrl  = "{$link_ajax_fetch_erli_categories|escape:'javascript'}";
  var searchUrl = "{$link_ajax_search_erli_categories|escape:'javascript'}";

  var statusEl = document.getElementById('erli-cats-status');
  var btnFetch = document.getElementById('erli-fetch-cats');

  function setStatus(txt) {
    if (statusEl) statusEl.textContent = txt || '';
  }

  function getJson(url) {
    return fetch(url, { credentials: 'same-origin' }).then(function (r) {
      // twardsze: jak serwer zwróci HTML/500, nie próbuj r.json() bezwarunkowo
      if (!r.ok) {
        return r.text().then(function (t) {
          throw new Error('HTTP ' + r.status + ' ' + (t || ''));
        });
      }
      return r.json();
    });
  }

  // ====== Pobierz i zapisz słownik ERLI do DB ======
  if (btnFetch) {
    btnFetch.addEventListener('click', function () {
      setStatus('Pobieram kategorie z ERLI i zapisuję do bazy...');
      btnFetch.disabled = true;

      getJson(fetchUrl).then(function (j) {
        btnFetch.disabled = false;

        if (!j || !j.success) {
          setStatus('Błąd: ' + (j && j.message ? j.message : 'unknown'));
          return;
        }

        setStatus('OK. fetched=' + (j.fetched || 0) + ', pages=' + (j.pages || 0) + ', w bazie=' + (j.total_in_db || 0));
        alert('Słownik ERLI zaktualizowany. Liczba kategorii w bazie: ' + (j.total_in_db || 0));
      }).catch(function (e) {
        btnFetch.disabled = false;
        console.error(e);
        setStatus('Błąd pobierania.');
      });
    });
  }

  function escAttr(s) {
    return String(s || '').replace(/"/g, '&quot;');
  }

  function renderResults(box, items) {
    if (!box) return;

    if (!items || !items.length) {
      box.innerHTML = '<div class="erli-item" style="cursor:default;color:#777;">Brak wyników</div>';
      box.style.display = 'block';
      return;
    }

    box.innerHTML = items.map(function (it) {
      var id = escAttr(it.id);
      var name = escAttr(it.name);
      var bc = escAttr(it.breadcrumb || '');

      return (
        '<div class="erli-item" data-id="' + id + '" data-name="' + name + '" data-breadcrumb="' + bc + '">' +
          '<b>' + (it.name || '') + '</b>' +
          (it.breadcrumb ? ('<small>' + it.breadcrumb + '</small>') : '') +
        '</div>'
      );
    }).join('');

    box.style.display = 'block';
  }

  function closeResults(box) {
    if (!box) return;
    box.style.display = 'none';
    box.innerHTML = '';
  }

  function attachPicker(tr) {
    var input   = tr.querySelector('.erli-cat-search');
    var results = tr.querySelector('.erli-results');
    var idField = tr.querySelector('.erli-cat-id');
    var nameField = tr.querySelector('.erli-cat-name');
    var picked  = tr.querySelector('.erli-picked');

    if (!input || !results || !idField) return;
    if (input.__erliBound) return;
    input.__erliBound = true;

    var tmr = null;

    input.addEventListener('input', function () {
      var q = (input.value || '').trim();

      if (q.length < 2) {
        closeResults(results);
        return;
      }

      if (tmr) clearTimeout(tmr);

      tmr = setTimeout(function () {
        var url = searchUrl +
          '&q=' + encodeURIComponent(q) +
          '&leaf=1' +
          '&limit=30';

        getJson(url).then(function (j) {
          if (!j || !j.success) {
            results.innerHTML = '<div class="erli-item" style="cursor:default;color:#b00;">Błąd wyszukiwania</div>';
            results.style.display = 'block';
            return;
          }
          renderResults(results, j.items || []);
        }).catch(function (e) {
          console.error(e);
          results.innerHTML = '<div class="erli-item" style="cursor:default;color:#b00;">Błąd wyszukiwania</div>';
          results.style.display = 'block';
        });
      }, 250);
    });

    results.addEventListener('click', function (e) {
      var el = e.target.closest('.erli-item');
      if (!el || !el.dataset || !el.dataset.id) return;

      var id = el.dataset.id;
      var name = el.dataset.name || '';
      var bc = el.dataset.breadcrumb || '';

      idField.value = id;
      if (nameField) nameField.value = name;

      input.value = name + (bc ? (' — ' + bc) : '');
      if (picked) {
        picked.innerHTML =
          '<small><b>Aktualnie:</b> ' + name +
          (bc ? (' <span style="color:#777;">(' + bc + ')</span>') : '') +
          '</small>';
      }

      closeResults(results);
    });

    document.addEventListener('click', function (e) {
      if (!tr.contains(e.target)) closeResults(results);
    });
  }

  document.querySelectorAll('#erli-map-table tbody tr').forEach(attachPicker);
})();
</script>
