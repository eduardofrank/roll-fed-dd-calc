jQuery(function ($) {
  if ($('#fac-save-woo').length === 0) {
    return;
  }

  var savedId = parseInt(facAdmin.savedProductId, 10) || 0;
  var savedInkjetId = parseInt(facAdmin.savedInkjetProductId, 10) || 0;
  var wooActive = facAdmin.wooActive;
  var searchTimer = null;

  function escHtml(s) {
    return $('<div>').text(s || '').html();
  }

  function renderSelected(p, prefix) {
    var sku = p.sku ? ' — SKU: ' + p.sku : '';
    $('#' + prefix + '-selected-text').remove();
    $('#' + prefix + '-selected-product').html(
      '<span style="background:#2271b1;color:#fff;border-radius:4px;padding:2px 8px;font-size:12px;font-weight:600;">ID ' +
        p.id +
        '</span>' +
        '<strong style="flex:1">' +
        escHtml(p.title) +
        '</strong>' +
        '<span style="color:#888;font-size:12px;">' +
        escHtml(sku) +
        '</span>'
    );
    $('#' + prefix + '-product-id-field').val(p.id);
  }

  function bindProductSearch(inputSel, resultsSel, spinnerSel, prefix) {
    $(inputSel).on('input', function () {
      clearTimeout(searchTimer);
      var term = $(this).val().trim(),
        $res = $(resultsSel);
      if (term.length < 2) {
        $res.hide();
        return;
      }
      searchTimer = setTimeout(function () {
        $(spinnerSel).show();
        $.get(
          facAdmin.ajaxUrl,
          { action: 'fac_search_products', nonce: facAdmin.nonce, term: term },
          function (res) {
            $(spinnerSel).hide();
            $res.empty();
            if (!res.success || !res.data.length) {
              $res.html('<div style="padding:10px 14px;color:#888;">No products found.</div>').show();
              return;
            }
            res.data.forEach(function (p) {
              var $row = $(
                '<div data-id="' +
                  p.id +
                  '" style="padding:10px 14px;cursor:pointer;border-bottom:1px solid #f0f0f0;display:flex;align-items:center;gap:8px;"><span style="background:#2271b1;color:#fff;border-radius:3px;padding:1px 7px;font-size:11px;font-weight:600;">' +
                  p.id +
                  '</span>' +
                  escHtml(p.title) +
                  (p.sku ? ' <span style="color:#888;font-size:11px;">SKU: ' + escHtml(p.sku) + '</span>' : '') +
                  '</div>'
              );
              $row
                .on('mouseenter', function () {
                  $(this).css('background', '#f0f6ff');
                })
                .on('mouseleave', function () {
                  $(this).css('background', '');
                })
                .on('click', function () {
                  renderSelected(p, prefix);
                  $(inputSel).val('');
                  $res.hide();
                });
              $res.append($row);
            });
            $res.show();
            var offset = $(inputSel).offset(),
              h = $(inputSel).outerHeight();
            $res.css({ top: offset.top + h + 4, left: offset.left });
          }
        );
      }, 300);
    });
  }

  function loadSavedProduct(id, prefix) {
    if (!id || !wooActive) return;
    $.get(facAdmin.ajaxUrl, { action: 'fac_search_products', nonce: facAdmin.nonce, term: '' }, function (res) {
      if (res.success) {
        var found = res.data.find(function (p) {
          return p.id === id;
        });
        renderSelected(
          found || {
            id: id,
            title: 'Product #' + id,
            sku: '',
          },
          prefix
        );
      }
    });
  }

  bindProductSearch('#fac-product-search', '#fac-product-results', '#fac-search-spinner', 'fac');
  bindProductSearch(
    '#fac-inkjet-product-search',
    '#fac-inkjet-product-results',
    '#fac-inkjet-search-spinner',
    'fac-inkjet'
  );
  loadSavedProduct(savedId, 'fac');
  loadSavedProduct(savedInkjetId, 'fac-inkjet');

  $(document).on('click', function (e) {
    if (!$(e.target).closest('#fac-product-search,#fac-product-results').length) $('#fac-product-results').hide();
    if (!$(e.target).closest('#fac-inkjet-product-search,#fac-inkjet-product-results').length) $('#fac-inkjet-product-results').hide();
  });

  $('#fac-save-woo').on('click', function () {
    var id = parseInt($('#fac-product-id-field').val(), 10) || 0;
    var inkjetId = parseInt($('#fac-inkjet-product-id-field').val(), 10) || 0;
    var $btn = $(this).text('Saving…').prop('disabled', true);
    $.post(
      facAdmin.ajaxUrl,
      {
        action: 'fac_save_woo_product',
        nonce: facAdmin.nonce,
        product_id: id,
        inkjet_product_id: inkjetId,
        digest_enabled: $('#fac-digest-enabled').is(':checked') ? 1 : 0,
        digest_recipient: $('#fac-digest-recipient').val(),
      },
      function (res) {
        $btn.text('💾 Save WooCommerce Settings').prop('disabled', false);
        var $n = $('#fac-woo-notice');
        if (res.success) {
          $n
            .removeClass('notice-error')
            .addClass('notice-success')
            .find('p')
            .text('✅ WooCommerce settings saved! Archival ID: ' + res.data.product_id + ', Inkjet ID: ' + res.data.inkjet_product_id);
        } else {
          $n.removeClass('notice-success').addClass('notice-error').find('p').text('❌ Error: ' + (res.data || 'Unknown error'));
        }
        $n.show();
        setTimeout(function () {
          $n.fadeOut();
        }, 5000);
      }
    ).fail(function () {
      $btn.text('💾 Save WooCommerce Settings').prop('disabled', false);
      $('#fac-woo-notice').removeClass('notice-success').addClass('notice-error').find('p').text('❌ Network error.').end().show();
    });
  });
});
