jQuery(function ($) {
  function roundTo(value, decimals) {
    var factor = Math.pow(10, decimals);
    return Math.round(value * factor) / factor;
  }

  function parseStepperValue($input, decimals) {
    var raw = parseFloat($input.val());
    if (isNaN(raw)) {
      return 0;
    }
    return decimals > 0 ? roundTo(raw, decimals) : Math.round(raw);
  }

  function setStepperValue($input, value, decimals) {
    var next = decimals > 0 ? roundTo(value, decimals) : Math.round(value);
    $input.val(next);
    $input.trigger('change');
  }

  function initNumSteppers(root) {
    $(root || document)
      .find('.fac-num-stepper')
      .each(function () {
        var $wrap = $(this);
        if ($wrap.data('facStepperInit')) {
          return;
        }
        $wrap.data('facStepperInit', true);

        var $input = $wrap.find('.fac-num-stepper__input');
        var step = parseFloat($wrap.data('step'));
        if (isNaN(step) || step <= 0) {
          step = parseFloat($input.attr('step')) || 1;
        }
        var min = $wrap.data('min');
        min = min !== undefined && min !== '' ? parseFloat(min) : parseFloat($input.attr('min'));
        if (isNaN(min)) {
          min = 0;
        }
        var decimals = parseInt($wrap.data('decimals'), 10);
        if (isNaN(decimals)) {
          decimals = step < 1 ? String(step).split('.')[1].length : 0;
        }

        $wrap.find('.fac-num-stepper__btn').on('click', function () {
          var dir = parseInt($(this).data('dir'), 10) || 0;
          var current = parseStepperValue($input, decimals);
          var next = current + dir * step;
          if (next < min) {
            next = min;
          }
          setStepperValue($input, next, decimals);
        });
      });
  }

  window.facInitNumSteppers = initNumSteppers;
  initNumSteppers(document);
});
