jQuery(function ($) {
  // ── State ──
  var paperData = facAdmin.paperData || {};
  var rollWidths = facAdmin.rollWidths || [];
  var paperImages = facAdmin.paperImages || {};
  var bfMode = 'brand';
  var mediaFrame = null;

  // ── Helpers ──
  function slugify(s) {
    return s.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
  }
  function escHtml(s) {
    return $('<div>').text(s || '').html();
  }
  function allBrands() {
    return Object.keys(paperData);
  }
  function allFinishes(brand) {
    if (brand && paperData[brand]) return Object.keys(paperData[brand]);
    var all = {};
    $.each(paperData, function (b, fs) {
      $.each(fs, function (f) {
        all[f] = 1;
      });
    });
    return Object.keys(all);
  }
  function rollKeys() {
    return rollWidths.map(function (r) {
      return r.key;
    });
  }

  // ── Filters ──
  function populateFilters() {
    var $fb = $('#fac-filter-brand'),
      $ff = $('#fac-filter-finish');
    var bv = $fb.val(),
      fv = $ff.val();
    $fb.html('<option value="">All brands</option>');
    allBrands().forEach(function (b) {
      $fb.append('<option value="' + escHtml(b) + '">' + escHtml(b) + '</option>');
    });
    $fb.val(bv);
    $ff.html('<option value="">All finishes</option>');
    allFinishes($fb.val()).forEach(function (f) {
      $ff.append('<option value="' + escHtml(f) + '">' + escHtml(f) + '</option>');
    });
    $ff.val(fv);
  }

  // ── Card grid render ──
  function getImgUrl(p) {
    return paperImages[p.slug] || p.imageUrl || '';
  }

  function cardHtml(brand, finish, p) {
    var imgUrl = getImgUrl(p);
    var imgContent = imgUrl
      ? '<img src="' +
        escHtml(imgUrl) +
        '" alt="' +
        escHtml(p.name) +
        '" onerror="this.parentNode.innerHTML=\'<span class=dashicons.dashicons-format-image></span>\'">'
      : '<div class="fac-img-placeholder"><span class="dashicons dashicons-format-image"></span><span>No image</span></div>';

    var rolls = (p.availableRolls || [])
      .map(function (r) {
        return '<span class="fac-roll-tag">' + escHtml(r) + '"</span>';
      })
      .join('');

    return (
      '<div class="fac-card" data-brand="' +
      escHtml(brand) +
      '" data-finish="' +
      escHtml(finish) +
      '" data-slug="' +
      escHtml(p.slug) +
      '">' +
      '<div class="fac-card-img">' +
      imgContent +
      '<div class="fac-card-img-overlay"><span class="dashicons dashicons-edit" style="font-size:14px;width:14px;height:14px;"></span> Edit</div>' +
      '</div>' +
      '<div class="fac-card-body">' +
      '<p class="fac-card-name">' +
      escHtml(p.name) +
      '</p>' +
      '<p class="fac-card-slug">' +
      escHtml(p.slug) +
      '</p>' +
      '<div class="fac-card-stats">' +
      '<span class="fac-stat-pill">$' +
      parseFloat(p.rate).toFixed(4) +
      '</span>' +
      '<span class="fac-stat-pill">' +
      escHtml(p.gsm) +
      ' gsm</span>' +
      '</div>' +
      (rolls ? '<div class="fac-rolls">' + rolls + '</div>' : '') +
      '<p class="fac-card-desc">' +
      escHtml(p.description) +
      '</p>' +
      '<div class="fac-card-actions">' +
      '<button type="button" class="button fac-edit-paper"><span class="dashicons dashicons-edit"></span>Edit</button>' +
      '<div class="fac-spacer"></div>' +
      '<button type="button" class="button fac-del-btn fac-delete-paper" aria-label="Delete paper"><span class="dashicons dashicons-trash"></span></button>' +
      '</div>' +
      '</div>' +
      '</div>'
    );
  }

  function renderGroups() {
    populateFilters();
    var q = $('#fac-search').val().toLowerCase();
    var fb = $('#fac-filter-brand').val();
    var ff = $('#fac-filter-finish').val();
    var $groups = $('#fac-groups').empty();
    var total = 0;

    // Build finish→[papers] map respecting filters
    var groupMap = {};
    $.each(paperData, function (brand, finishes) {
      if (fb && brand !== fb) return;
      $.each(finishes, function (finish, papers) {
        if (ff && finish !== ff) return;
        papers.forEach(function (p) {
          if (
            q &&
            !(
              p.name.toLowerCase().indexOf(q) >= 0 ||
              p.slug.indexOf(q) >= 0 ||
              (p.description || '').toLowerCase().indexOf(q) >= 0
            )
          )
            return;
          var key = finish;
          if (!groupMap[key]) groupMap[key] = [];
          groupMap[key].push({ brand: brand, finish: finish, paper: p });
          total++;
        });
      });
    });

    if (total === 0) {
      $groups.html(
        '<div class="fac-empty"><span class="dashicons dashicons-search" style="font-size:28px;width:28px;height:28px;display:block;margin:0 auto 8px;color:#c3c4c7;"></span>No papers match your filters.</div>'
      );
      return;
    }

    Object.keys(groupMap)
      .sort()
      .forEach(function (finish) {
        var items = groupMap[finish];
        var $g = $('<div class="fac-group"></div>');
        $g.append(
          '<div class="fac-group-header">' +
            '<span class="fac-group-label">' +
            escHtml(finish) +
            '</span>' +
            '<div class="fac-group-rule"></div>' +
            '<span class="fac-group-count">' +
            items.length +
            '</span>' +
            '</div>'
        );
        var $cards = $('<div class="fac-cards"></div>');
        items.forEach(function (item) {
          $cards.append(cardHtml(item.brand, item.finish, item.paper));
        });
        $g.append($cards);
        $groups.append($g);
      });
  }

  // ── Media library ──
  function openMediaLibrary(onSelect) {
    if (mediaFrame) {
      mediaFrame.open();
      return;
    }
    mediaFrame = wp.media({
      title: 'Select Paper Image',
      button: { text: 'Use this image' },
      library: { type: 'image' },
      multiple: false,
    });
    mediaFrame.on('select', function () {
      var att = mediaFrame.state().get('selection').first().toJSON();
      onSelect(att.url, att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url);
    });
    mediaFrame.open();
  }

  // ── Modal helpers ──
  function populateModalDatalists() {
    $('#fm-brand-list').empty();
    allBrands().forEach(function (b) {
      $('#fm-brand-list').append('<option value="' + escHtml(b) + '">');
    });
    $('#fm-finish-list').empty();
    allFinishes($('#fm-brand').val()).forEach(function (f) {
      $('#fm-finish-list').append('<option value="' + escHtml(f) + '">');
    });
  }

  function populateRollCheckboxes(selected) {
    var $wrap = $('#fm-rolls-wrap').empty();
    rollKeys().forEach(function (k) {
      var chk = (selected || []).indexOf(k) >= 0 ? 'checked' : '';
      $wrap.append('<label><input type="checkbox" class="fm-roll-cb" value="' + escHtml(k) + '" ' + chk + '> ' + escHtml(k) + '"</label>');
    });
  }

  function setModalImage(url) {
    $('#fm-imageurl').val(url || '');
    var $wrap = $('#fm-img-preview-wrap');
    if (url) {
      $wrap.html('<img src="' + escHtml(url) + '" style="width:100%;height:100%;object-fit:cover;">');
      $('#fm-img-remove').show();
    } else {
      $wrap.html('<span class="dashicons dashicons-format-image"></span>');
      $('#fm-img-remove').hide();
    }
  }

  function openAddModal() {
    $('#fac-modal-title').text('Add Paper');
    $('#fm-brand,#fm-finish,#fm-name,#fm-slug,#fm-description').val('');
    $('#fm-rate').val('0.0414');
    $('#fm-gsm').val('310');
    $('#fm-edit-key').val('');
    setModalImage('');
    mediaFrame = null;
    populateModalDatalists();
    populateRollCheckboxes(['44']);
    $('#fac-modal-overlay').show();
  }

  function openEditModal(brand, finish, slug) {
    var papers = (paperData[brand] || {})[finish] || [];
    var p = papers.find(function (x) {
      return x.slug === slug;
    });
    if (!p) {
      alert('Paper not found.');
      return;
    }

    $('#fac-modal-title').text('Edit Paper');
    $('#fm-brand').val(brand);
    $('#fm-finish').val(finish);
    $('#fm-name').val(p.name);
    $('#fm-slug').val(p.slug);
    $('#fm-rate').val(p.rate);
    $('#fm-gsm').val(p.gsm);
    $('#fm-description').val(p.description || '');
    $('#fm-edit-key').val(brand + '|' + finish + '|' + slug);
    mediaFrame = null;

    var imgUrl = getImgUrl(p);
    setModalImage(imgUrl);
    populateModalDatalists();
    populateRollCheckboxes(p.availableRolls || []);
    $('#fac-modal-overlay').show();
  }

  function closeModal() {
    $('#fac-modal-overlay').hide();
  }

  // ── Save paper ──
  function savePaperFromModal() {
    var brand = $.trim($('#fm-brand').val());
    var finish = $.trim($('#fm-finish').val());
    var name = $.trim($('#fm-name').val());
    var slug = $.trim($('#fm-slug').val()) || slugify(name);
    var rate = parseFloat($('#fm-rate').val()) || 0.0414;
    var gsm = parseInt($('#fm-gsm').val()) || 310;
    var desc = $.trim($('#fm-description').val());
    var imageUrl = $.trim($('#fm-imageurl').val());
    var editKey = $('#fm-edit-key').val();
    var rolls = [];
    $('.fm-roll-cb:checked').each(function () {
      rolls.push($(this).val());
    });

    if (!brand || !finish || !name) {
      alert('Brand, Finish, and Name are required.');
      return;
    }

    // Remove old entry on edit
    if (editKey) {
      var parts = editKey.split('|'),
        ob = parts[0],
        of = parts[1],
        os = parts[2];
      if (paperData[ob] && paperData[ob][of]) {
        paperData[ob][of] = paperData[ob][of].filter(function (x) {
          return x.slug !== os;
        });
        if (!paperData[ob][of].length) delete paperData[ob][of];
        if (!Object.keys(paperData[ob]).length) delete paperData[ob];
      }
      // carry the old image override into paperImages keyed by new slug
      if (imageUrl && os !== slug) delete paperImages[os];
    }

    if (!paperData[brand]) paperData[brand] = {};
    if (!paperData[brand][finish]) paperData[brand][finish] = [];
    var colIndex = paperData[brand][finish].length + 1;
    paperData[brand][finish].push({
      name: name,
      slug: slug,
      colIndex: colIndex,
      rate: rate,
      gsm: gsm,
      description: desc,
      availableRolls: rolls,
      imageUrl: imageUrl,
    });

    // Sync to paperImages store so card previews update immediately
    if (imageUrl) paperImages[slug] = imageUrl;
    else delete paperImages[slug];

    closeModal();
    renderGroups();
    showNotice('Paper saved locally. Click "Save All Changes" to persist.', false);
  }

  // ── Delete paper ──
  function deletePaper(brand, finish, slug) {
    if (!confirm('Delete "' + slug + '"?')) return;
    if (paperData[brand] && paperData[brand][finish]) {
      paperData[brand][finish] = paperData[brand][finish].filter(function (x) {
        return x.slug !== slug;
      });
      if (!paperData[brand][finish].length) delete paperData[brand][finish];
      if (!Object.keys(paperData[brand]).length) delete paperData[brand];
    }
    renderGroups();
    showNotice('Paper removed. Click "Save All Changes" to persist.', false);
  }

  // ── Save to server ──
  function saveAllPapers() {
    var $btn = $('#fac-save-papers').text('Saving…').prop('disabled', true);

    // Save paper data
    $.post(
      facAdmin.ajaxUrl,
      {
        action: 'fac_save_paper_data',
        nonce: facAdmin.nonce,
        paper_data: JSON.stringify(paperData),
      },
      function (res) {
        if (!res.success) {
          showNotice('❌ Error: ' + (res.data || 'Unknown error'), true);
          $btn.text('💾 Save All Changes').prop('disabled', false);
          return;
        }

        // Also save paper images
        $.post(
          facAdmin.ajaxUrl,
          {
            action: 'fac_save_paper_images',
            nonce: facAdmin.nonce,
            paper_images: JSON.stringify(paperImages),
          },
          function (r2) {
            $btn.text('💾 Save All Changes').prop('disabled', false);
            if (r2.success) showNotice('✅ All paper data saved successfully!', false);
            else showNotice('❌ Error saving images: ' + (r2.data || 'Unknown error'), true);
          }
        ).fail(function () {
          $btn.text('💾 Save All Changes').prop('disabled', false);
          showNotice('❌ Network error.', true);
        });
      }
    ).fail(function () {
      $btn.text('💾 Save All Changes').prop('disabled', false);
      showNotice('❌ Network error — could not save.', true);
    });
  }

  function showNotice(msg, isError) {
    var $n = $('#fac-notice');
    $n.removeClass('notice-success notice-error').addClass(isError ? 'notice-error' : 'notice-success');
    $n.find('p').text(msg);
    $n.show();
    setTimeout(function () {
      $n.fadeOut();
    }, 5000);
  }

  // ── Brand/Finish modal ──
  function openBfModal(mode) {
    bfMode = mode;
    $('#fac-bf-modal-title').text(mode === 'brand' ? 'Add Brand' : 'Add Finish Category');
    $('#fac-bf-label').text(mode === 'brand' ? 'Brand name' : 'Finish category name');
    $('#fac-bf-value').val('');
    if (mode === 'finish') {
      var $sel = $('#fac-bf-brand-select').empty();
      allBrands().forEach(function (b) {
        $sel.append('<option>' + escHtml(b) + '</option>');
      });
      $('#fac-bf-brand-row').show();
    } else {
      $('#fac-bf-brand-row').hide();
    }
    $('#fac-bf-modal-overlay').show();
  }

  function saveBf() {
    var val = $.trim($('#fac-bf-value').val());
    if (!val) {
      alert('Please enter a name.');
      return;
    }
    if (bfMode === 'brand') {
      if (!paperData[val]) paperData[val] = {};
    } else {
      var brand = $('#fac-bf-brand-select').val();
      if (!paperData[brand]) paperData[brand] = {};
      if (!paperData[brand][val]) paperData[brand][val] = [];
    }
    $('#fac-bf-modal-overlay').hide();
    renderGroups();
    showNotice((bfMode === 'brand' ? 'Brand' : 'Finish') + ' added. Click "Save All Changes" to persist.', false);
  }

  // ── Media button handler ──
  $('#fm-media-btn').on('click', function () {
    openMediaLibrary(function (fullUrl) {
      setModalImage(fullUrl);
    });
  });
  $('#fm-img-remove').on('click', function () {
    setModalImage('');
    mediaFrame = null;
  });

  // ── Event bindings ──
  $('#fac-add-paper').on('click', openAddModal);
  $('#fac-add-brand').on('click', function () {
    openBfModal('brand');
  });
  $('#fac-add-finish').on('click', function () {
    openBfModal('finish');
  });
  $('#fac-save-papers').on('click', saveAllPapers);
  $('#fac-modal-close,#fac-modal-cancel').on('click', closeModal);
  $('#fac-bf-modal-close,#fac-bf-modal-cancel').on('click', function () {
    $('#fac-bf-modal-overlay').hide();
  });
  $('#fac-modal-save').on('click', savePaperFromModal);
  $('#fac-bf-modal-save').on('click', saveBf);
  $('#fac-modal-overlay').on('click', function (e) {
    if ($(e.target).is('#fac-modal-overlay')) closeModal();
  });
  $('#fac-bf-modal-overlay').on('click', function (e) {
    if ($(e.target).is('#fac-bf-modal-overlay')) $('#fac-bf-modal-overlay').hide();
  });

  $('#fm-name').on('input', function () {
    if (!$('#fm-edit-key').val()) $('#fm-slug').val(slugify($(this).val()));
  });
  $('#fm-brand').on('input', populateModalDatalists);
  $('#fac-filter-brand,#fac-filter-finish').on('change', renderGroups);
  $('#fac-search').on('input', renderGroups);

  // Edit via card overlay or button
  $(document).on('click', '.fac-card-img, .fac-edit-paper', function (e) {
    e.preventDefault();
    var $card = $(this).closest('.fac-card');
    openEditModal($card.data('brand'), $card.data('finish'), $card.data('slug'));
  });
  $(document).on('click', '.fac-delete-paper', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var $card = $(this).closest('.fac-card');
    deletePaper($card.data('brand'), $card.data('finish'), $card.data('slug'));
  });

  // ── Init ──
  renderGroups();
});
