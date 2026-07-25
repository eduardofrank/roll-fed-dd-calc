jQuery(function ($) {
  if ($('#fac-inkjet-tbody').length === 0) {
    return;
  }

  var papers = Array.isArray(facAdmin.inkjetPaperData) ? facAdmin.inkjetPaperData.slice() : [];
  var rollKeys = ['44', '50', '60', '64'];
  var categoryLabels = {
    papers: 'Papers',
    canvas: 'Canvas',
    vinyl_fabric: 'Vinyl & Fabric',
    other: 'Other Choices',
  };
  var slugCategoryFallback = {
    artdeco_310g_velvet_textured_bright_white_matte: 'papers',
    artdeco_8_mil_universal_gloss_photo_paper: 'papers',
    epson_enhanced_matte_inkjet_paper_192: 'papers',
    epson_metallic_photo_paper_glossy_257: 'papers',
    epson_premium_luster_photo_260: 'papers',
    artdeco_17_mil_high_resolution_water_resistant_gloss_canvas: 'canvas',
    artdeco_22_mil_polycotton_water_resistant_matte_canvas: 'canvas',
    artdeco_22_5_mil_canvas_metallic_pearl: 'canvas',
    sihl_3209_quickstick_aqueous_adhesive_backed_fabric: 'vinyl_fabric',
    sihl_3585_premium_vinyl_sa_270_gloss: 'vinyl_fabric',
    sihl_3988_classic_vinyl_psa_matte: 'vinyl_fabric',
    sihl_3148_absolute_clear_film_with_interleaf_paper: 'other',
  };
  var editIndex = -1;

  function resolveCategory(paper) {
    var key = paper && paper.category ? String(paper.category) : '';
    if (key && categoryLabels[key]) {
      return key;
    }
    return slugCategoryFallback[(paper && paper.slug) || ''] || 'other';
  }

  function escHtml(s) {
    return $('<div>').text(s || '').html();
  }

  function slugify(str) {
    return (str || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
  }

  function sortedPapers(list) {
    return list.slice().sort(function (a, b) {
      return (a.name || '').localeCompare(b.name || '');
    });
  }

  function showNotice(msg, ok) {
    var $n = $('#fac-inkjet-notice');
    $n
      .removeClass('notice-error notice-success')
      .addClass(ok ? 'notice-success' : 'notice-error')
      .find('p')
      .text(msg)
      .end()
      .show();
    setTimeout(function () {
      $n.fadeOut();
    }, 5000);
  }

  function renderRollCheckboxes(selected) {
    var html = '';
    rollKeys.forEach(function (key) {
      var checked = (selected || []).indexOf(key) !== -1 ? ' checked' : '';
      html += '<label><input type="checkbox" value="' + key + '"' + checked + '> ' + key + '"</label>';
    });
    $('#fac-inkjet-rolls').html(html);
  }

  function getSelectedRolls() {
    return $('#fac-inkjet-rolls input:checked')
      .map(function () {
        return this.value;
      })
      .get();
  }

  function renderTable() {
    var term = ($('#fac-inkjet-search').val() || '').toLowerCase();
    var $tb = $('#fac-inkjet-tbody').empty();
    sortedPapers(papers).forEach(function (p) {
      if (term && (p.name || '').toLowerCase().indexOf(term) === -1 && (p.slug || '').toLowerCase().indexOf(term) === -1) return;
      var idx = papers.indexOf(p);
      var rolls = (p.availableRolls || []).join(', ');
      var $tr = $('<tr></tr>');
      $tr.append('<td><strong>' + escHtml(p.name) + '</strong></td>');
      $tr.append('<td>' + escHtml(categoryLabels[resolveCategory(p)]) + '</td>');
      $tr.append('<td><code>' + escHtml(p.slug) + '</code></td>');
      $tr.append('<td>' + escHtml(String(p.rate)) + '</td>');
      $tr.append('<td>' + escHtml(String(p.gsm || 0)) + '</td>');
      $tr.append('<td>' + escHtml(rolls) + '</td>');
      $tr.append('<td>' + escHtml(p.description || '') + '</td>');
      $tr.append(
        '<td><button type="button" class="button button-small fac-inkjet-edit" data-idx="' +
          idx +
          '">Edit</button> <button type="button" class="button button-small fac-inkjet-del" data-idx="' +
          idx +
          '">Delete</button></td>'
      );
      $tb.append($tr);
    });
    if (!$tb.children().length) {
      $tb.append('<tr><td colspan="8" style="text-align:center;color:#888;padding:24px;">No inkjet papers found.</td></tr>');
    }
  }

  function openModal(index) {
    editIndex = typeof index === 'number' ? index : -1;
    var p =
      editIndex >= 0
        ? papers[editIndex]
        : { name: '', slug: '', category: 'papers', rate: 0.0414, gsm: 0, availableRolls: rollKeys.slice(), description: '' };
    $('#fac-inkjet-modal-title').text(editIndex >= 0 ? 'Edit Inkjet Paper' : 'Add Inkjet Paper');
    $('#fac-inkjet-name').val(p.name || '');
    $('#fac-inkjet-category').val(resolveCategory(p));
    $('#fac-inkjet-slug').val(p.slug || '');
    $('#fac-inkjet-rate').val(p.rate != null ? p.rate : 0.0414);
    $('#fac-inkjet-gsm').val(p.gsm != null ? p.gsm : 0);
    $('#fac-inkjet-desc').val(p.description || '');
    renderRollCheckboxes(p.availableRolls || rollKeys.slice());
    $('#fac-inkjet-modal-overlay').show();
  }

  function closeModal() {
    $('#fac-inkjet-modal-overlay').hide();
    editIndex = -1;
  }

  renderTable();

  $('#fac-inkjet-search').on('input', renderTable);
  $('#fac-inkjet-add').on('click', function () {
    openModal(-1);
  });
  $('#fac-inkjet-modal-close, #fac-inkjet-modal-cancel').on('click', closeModal);
  $('#fac-inkjet-name').on('input', function () {
    if (editIndex < 0 && !$('#fac-inkjet-slug').data('manual')) {
      $('#fac-inkjet-slug').val(slugify($(this).val()));
    }
  });
  $('#fac-inkjet-slug').on('input', function () {
    $(this).data('manual', true);
  });

  $(document).on('click', '.fac-inkjet-edit', function () {
    openModal(parseInt($(this).data('idx'), 10));
  });
  $(document).on('click', '.fac-inkjet-del', function () {
    var idx = parseInt($(this).data('idx'), 10);
    if (!confirm('Delete this inkjet paper?')) return;
    papers.splice(idx, 1);
    renderTable();
  });

  $('#fac-inkjet-modal-save').on('click', function () {
    var entry = {
      name: $('#fac-inkjet-name').val().trim(),
      slug: $('#fac-inkjet-slug').val().trim(),
      category: $('#fac-inkjet-category').val() || 'other',
      rate: parseFloat($('#fac-inkjet-rate').val()) || 0,
      gsm: parseInt($('#fac-inkjet-gsm').val(), 10) || 0,
      availableRolls: getSelectedRolls(),
      description: $('#fac-inkjet-desc').val().trim(),
    };
    if (!entry.name || !entry.slug) {
      alert('Name and slug are required.');
      return;
    }
    if (editIndex >= 0) papers[editIndex] = entry;
    else papers.push(entry);
    closeModal();
    renderTable();
  });

  $('#fac-inkjet-save').on('click', function () {
    var $btn = $(this).text('Saving…').prop('disabled', true);
    $.post(
      facAdmin.ajaxUrl,
      {
        action: 'fac_save_inkjet_paper_data',
        nonce: facAdmin.nonce,
        paper_data: JSON.stringify(papers),
      },
      function (res) {
        $btn.text('Save Inkjet Papers').prop('disabled', false);
        if (res.success) {
          showNotice('Inkjet paper data saved.', true);
          $('#fac-inkjet-save-status').text('Saved at ' + new Date().toLocaleTimeString());
        } else {
          showNotice('Error: ' + (res.data || 'Unknown error'), false);
        }
      }
    ).fail(function () {
      $btn.text('Save Inkjet Papers').prop('disabled', false);
      showNotice('Network error while saving.', false);
    });
  });
});
