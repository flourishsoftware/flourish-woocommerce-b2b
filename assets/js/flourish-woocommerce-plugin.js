(function ($) {
  $(document).ready(function () {
    $('#flourish-woocommerce-plugin-filter-brands').on('click', function () {
      $('#flourish-woocommerce-plugin-brand-selection').toggle(this.checked);
    });

    // Validation rule for minimum and maximum order quantity
    const minOrderField = $('#_min_order_quantity');
    const maxOrderField = $('#_max_order_quantity');

    // Helper to show error messages below the input field
    function showError(field, message) {
      let errorSpan = field.next('.error-message');
      if (!errorSpan.length) {
        errorSpan = $('<span class="error-message" style="display: flex; color: #f34e4e; font-size: 12px; padding: 0px 4px; width: 100%;"></span>');
        field.after(errorSpan);
      }
      errorSpan.text(message);
    }

    // Helper to clear error messages
    function clearError(field) {
      field.next('.error-message').remove();
    }

    // Function to validate the input while typing
    function validateInput(event) {
      let value = $(this).val();

      // Rule 1: Prevent negative values
      if (value.startsWith('-')) {
        $(this).val(value.replace(/^-/, '')); // Remove the leading negative symbol
        showError($(this), "Negative values are not allowed.");
      } else {
        clearError($(this));
      }

      // Rule 2: Prevent values starting with '0'
      if (/^0/.test(value)) {
        $(this).val(value.replace(/^0/, "")); // Remove leading zero
        showError($(this), "Values cannot start with '0'.");
      } else {
        clearError($(this));
      }

      // Rule 3: Prevent any alphabetic characters (a-z, A-Z)
      if (/[a-zA-Z]/.test(value)) {
        $(this).val(value.replace(/[a-zA-Z]/g, '')); // Remove alphabetic characters
        showError($(this), "Alphabetic characters are not allowed.");
      } else {
        clearError($(this));
      }

      // Rule 4: Prevent values starting with `+`
      if (value.startsWith('+')) {
        $(this).val(value.replace(/^\+/, '')); // Remove the leading `+`
        showError($(this), "Values cannot start with '+'.");
      } else {
        clearError($(this));
      }

      // Rule 5: Allow only numeric input (strip non-numeric characters)
      if (/[^0-9]/.test(value)) {
        $(this).val(value.replace(/[^0-9]/g, '')); // Remove non-numeric characters
        showError($(this), "Only numeric values are allowed.");
      } else {
        clearError($(this));
      }
    }

    // Function to validate min/max relationship after input
    function validateMinMax() {
      const minVal = parseInt(minOrderField.val(), 10) || 0;
      const maxVal = parseInt(maxOrderField.val(), 10) || 0;

      // Rule 6: Min value should not be greater than max value
      if (minVal > maxVal && minOrderField.val() && maxOrderField.val()) {
        // If min is greater than max, show errors for both fields
        showError(minOrderField, "Minimum order quantity cannot be greater than maximum order quantity.");
        showError(maxOrderField, "Maximum order quantity cannot be less than minimum order quantity.");
      } else {
        clearError(minOrderField);
        clearError(maxOrderField);
      }

      // Rule 6: Min value should be less than max value
      // if (minVal >= maxVal && minOrderField.val() && maxOrderField.val()) {
      //   if (minVal === maxVal) {
      //     // If min is equal to max, show error only for the max field
      //     clearError(minOrderField); // Clear error on min field
      //     showError(maxOrderField, "Maximum order quantity must be greater than minimum order quantity.");
      //   } else {
      //     // If min is greater than max, show errors for both fields
      //     showError(minOrderField, "Minimum order quantity must be less than maximum order quantity.");
      //     showError(maxOrderField, "Maximum order quantity must be greater than minimum order quantity.");
      //   }
      // } else {
      //   clearError(minOrderField);
      //   clearError(maxOrderField);
      // }
    }

    // Attach real-time validation to input and blur events
    minOrderField.on('input', validateInput).on('blur', validateMinMax);
    maxOrderField.on('input', validateInput).on('blur', validateMinMax);

  });
})(jQuery);
