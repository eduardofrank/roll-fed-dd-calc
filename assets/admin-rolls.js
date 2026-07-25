jQuery(function ($) {
  if ($('#fac-roll-tbody').length === 0) {
    return;
  }

  var rolls = JSON.parse(JSON.stringify(facAdmin.rollWidths || []));

  function escHtml(s) {
    return $('<div>').text(s || '').html();
  }

  function stepperHtml(className, step, decimals, min, value) {
    return (
      '<div class="fac-num-stepper fac-num-stepper--compact" data-step="' +
      step +
      '" data-min="' +
      min +
      '" data-decimals="' +
      decimals +
      '">' +
      '<button type="button" class="fac-num-stepper__btn" data-dir="-1" aria-label="Decrease">−</button>' +
      '<input class="fac-num-stepper__input ' +
      className +
      '" type="number" step="' +
      step +
      '" min="' +
      min +
      '" value="' +
      value +
      '">' +
      '<button type="button" class="fac-num-stepper__btn" data-dir="1" aria-label="Increase">+</button>' +
      '</div>'
    );
  }

  function initRollSteppers() {
    if (typeof window.facInitNumSteppers === 'function') {
      window.facInitNumSteppers('#fac-roll-tbody');
    }
  }

  function renderRolls() {
    var $tb = $('#fac-roll-tbody').empty();
    rolls.forEach(function (r, i) {
      $tb.append(
        '<tr data-i="' +
          i +
          '">' +
          '<td><input class="r-key small-text" value="' +
          escHtml(r.key) +
          '"></td>' +
          '<td><input class="r-label" value="' +
          escHtml(r.label) +
          '"></td>' +
          '<td>' +
          stepperHtml('r-wi', 0.1, 1, 0, r.widthInches) +
          '</td>' +
          '<td>' +
          stepperHtml('r-ui', 0.01, 2, 0, r.usableInches) +
          '</td>' +
          '<td>' +
          stepperHtml('r-uc', 0.001, 3, 0, r.usableCm) +
          '</td>' +
          '<td><a href="#" class="fac-delete-roll button button-small" style="color:#b32d2e;">Delete</a></td>' +
          '</tr>'
      );
    });
    initRollSteppers();
  }

  function collectRolls() {
    rolls = [];
    $('#fac-roll-tbody tr').each(function () {
      var $tr = $(this);
      rolls.push({
        key: $.trim($tr.find('.r-key').val()),
        label: $.trim($tr.find('.r-label').val()),
        widthInches: parseFloat($tr.find('.r-wi').val()) || 0,
        usableInches: parseFloat($tr.find('.r-ui').val()) || 0,
        usableCm: parseFloat($tr.find('.r-uc').val()) || 0,
      });
    });
  }

  $('#fac-add-roll').on('click', function () {
    rolls.push({ key: '', label: '', widthInches: 0, usableInches: 0, usableCm: 0 });
    renderRolls();
  });

  $(document).on('click', '.fac-delete-roll', function (e) {
    e.preventDefault();
    if (confirm('Remove this roll width?')) {
      rolls.splice($(this).closest('tr').data('i'), 1);
      renderRolls();
    }
  });

  $('#fac-save-rolls').on('click', function () {
    collectRolls();
    var $btn = $(this).text('Saving…').prop('disabled', true);
    $.post(
      facAdmin.ajaxUrl,
      { action: 'fac_save_roll_widths', nonce: facAdmin.nonce, roll_widths: JSON.stringify(rolls) },
      function (res) {
        $btn.text('💾 Save Roll Widths').prop('disabled', false);
        var $n = $('#fac-roll-notice');
        if (res.success) {
          $n.removeClass('notice-error').addClass('notice-success').find('p').text('✅ Roll widths saved!');
        } else {
          $n.removeClass('notice-success').addClass('notice-error').find('p').text('❌ Error: ' + (res.data || ''));
        }
        $n.show();
        setTimeout(function () {
          $n.fadeOut();
        }, 4000);
      }
    );
  });

  renderRolls();
});
