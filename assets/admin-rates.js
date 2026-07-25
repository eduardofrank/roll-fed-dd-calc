jQuery(function ($) {
  if ($('#fac-save-rates').length === 0) {
    return;
  }

  var m = facAdmin.mountingRates || {};
  var t = facAdmin.turnaroundRates || {};
  $('#r-wg-in').val((m.inches || {}).white_gatorboard || 0);
  $('#r-bg-in').val((m.inches || {}).black_gatorboard || 0);
  $('#r-wg-cm').val((m.centimeters || {}).white_gatorboard || 0);
  $('#r-bg-cm').val((m.centimeters || {}).black_gatorboard || 0);
  $('#r-standard').val(t.standard || 1);
  $('#r-rush').val(t.rush || 1.15);

  $('#fac-save-rates').on('click', function () {
    var mounting = {
      inches: {
        no_mounting: 0,
        white_gatorboard: parseFloat($('#r-wg-in').val()) || 0,
        black_gatorboard: parseFloat($('#r-bg-in').val()) || 0,
      },
      centimeters: {
        no_mounting: 0,
        white_gatorboard: parseFloat($('#r-wg-cm').val()) || 0,
        black_gatorboard: parseFloat($('#r-bg-cm').val()) || 0,
      },
    };
    var turnaround = {
      standard: parseFloat($('#r-standard').val()) || 1,
      rush: parseFloat($('#r-rush').val()) || 1.15,
    };
    var $btn = $(this).text('Saving…').prop('disabled', true);
    $.post(
      facAdmin.ajaxUrl,
      {
        action: 'fac_save_rates',
        nonce: facAdmin.nonce,
        mounting_rates: JSON.stringify(mounting),
        turnaround_rates: JSON.stringify(turnaround),
      },
      function (res) {
        $btn.text('💾 Save Rates').prop('disabled', false);
        var $n = $('#fac-rates-notice');
        if (res.success) {
          $n.removeClass('notice-error').addClass('notice-success').find('p').text('✅ Rates saved!');
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
});
