(function ($) {
  'use strict';

  function initializePicker(input) {
    var $input = $(input);
    if (!$.fn || typeof $.fn.persianDatepicker !== 'function') {
      $input.prop('readonly', false);
      return;
    }
    if ($input.data('lunaPickerReady')) return;
    $input.data('lunaPickerReady', true).persianDatepicker({
      formatDate: 'YYYY/0M/0D'
    });
  }

  $(function () {
    $('.luna-pass-jalali-datepicker').each(function () {
      initializePicker(this);
    });
    $(document).on('click', '.luna-pass-date-trigger', function () {
      var input = $(this).siblings('.luna-pass-jalali-datepicker').get(0);
      if (!input) return;
      initializePicker(input);
      $(input).trigger('click').trigger('focus');
    });
  });
})(jQuery);
