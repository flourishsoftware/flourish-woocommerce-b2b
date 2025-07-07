jQuery(document).ready(function ($) {    
    //-----------------Case Size js Start-----------------
    // Handle Add/Edit Case Size
    function handleCaseSizeAction(data, callback) {
        $.ajax({
            url: ajax_object.ajax_url,
            method: 'POST',
            data: data,
            success: function (response) {
                if (response.success) {
                    alert(response.data.message);
                    callback(response.data);
                } else {
                    alert(response.data.message);
                }
            },
            error: function () {
                alert('An error occurred while processing the request.');
            },
        });
    }

    // Add New Case Size
    $('#add-case-row').on('click', function () {
        const data = {
            action: 'add_edit_case_size',
            security: ajax_object.nonce,
            case_name: $('#case-name').val(),
            quantity: $('#quantity').val(),
            base_uom: $('#uom').val(),
        };

        if (!data.case_name || !data.quantity || !data.base_uom) {
            alert('All fields are required.');
            return;
        }

        handleCaseSizeAction(data, function (responseData) {
            const caseSizeRows = $('#case-size-rows');
            const noRecordsRow = $('#no-records-row');

            if (noRecordsRow.length) noRecordsRow.remove();
            caseSizeRows.append(responseData.row_html);

            $('#case-name').val('');
            $('#quantity').val('');
            $('#uom').val('ea');
        });
    });

    // Edit Case Size
    $(document).on('click', '.save-case-size', function (e) {
        e.preventDefault();

        const row = $(this).closest('tr');
        const data = {
            action: 'add_edit_case_size',
            security: ajax_object.nonce,
            term_id: $(this).data('term-id'),
            taxonomy: $(this).data('taxonomy'),
            case_name: row.find('.edit-casename').val(),
            quantity: row.find('.edit-quantity').val().trim(),
            base_uom: row.find('.edit-base_uom').val().trim(),
        };

        handleCaseSizeAction(data, function (responseData) {
            row.replaceWith(responseData.row_html);
        });
    });
});

/* Edit Case size */
jQuery(document).on('click', '.edit-case-size', function (e) {
    e.preventDefault();

    if (!confirm('Are you sure you want to edit this case size?')) {
        return; // Exit if the user cancels
    }

    const row = jQuery(this).closest('tr'); // Get the current row
    const termId = jQuery(this).data('term-id');
    const taxonomy = jQuery(this).data('term-name');
    const currentCaseName = row.find('.casename').text().trim();
    const currentQuantity = row.find('.quantity').text().trim();
    const currentBaseUom = row.find('.base_uom').text().trim();

    // Store the original row HTML in a data attribute
    row.data('original-html', row.html());

    // Make an AJAX call to fetch the UOM dropdown options
    jQuery.ajax({
        url: ajax_object.ajax_url, // Use the localized AJAX URL
        method: 'POST',
        data: {
            action: 'get_uom_dropdown_html_handler', // Define this in your PHP
            security: ajax_object.nonce // Use the nonce for security
        },
        success: function (response) {
            if (response.success && response.data.html) {
                // Inject the current UOM as selected in the dropdown
                const dropdownHtml = response.data.html.replace(
                    `<select id="uom" required>`,
                    `<select class="edit-base_uom" required><option value="${currentBaseUom}" selected>${currentBaseUom}</option>`
                );

                // Replace the row content with input fields and Save/Cancel buttons
                row.html(`
                        <td><input type="text" class="edit-casename" value="${currentCaseName}" required></td>
                        <td><input type="number" class="edit-quantity" value="${currentQuantity}" required></td>
                        <td>${dropdownHtml}</td>
                        <td>
                            <button class="save-case-size" data-term-id="${termId}" data-taxonomy="${taxonomy}">Save</button>
                            <button class="cancel-edit">Cancel</button>
                        </td>
                    `);
            } else {
                alert('Failed to fetch the UOM dropdown. Please try again.');
            }
        },
        error: function () {
            alert('An error occurred while fetching the UOM dropdown.');
        }
    });
});

jQuery(document).on('click', '.cancel-edit', function (e) {
    e.preventDefault();

    const row = jQuery(this).closest('tr');

    // Restore the original row HTML from the stored data
    const originalHtml = row.data('original-html');
    if (originalHtml) {
        row.html(originalHtml);
    }
});

/* Delete case size*/
jQuery(document).on('click', '.delete-case-size', function (e) {
    e.preventDefault();

    if (!confirm('Are you sure you want to delete this Case Name?')) {
        return;
    }

    const termId = jQuery(this).data('term-id');
    const taxonomy = jQuery(this).data('term-name');
    const row = jQuery(this).closest('tr'); // Get the closest row of the clicked "Delete" button

    jQuery.ajax({
        url: ajax_object.ajax_url, // Use localized AJAX URL
        type: 'POST',
        data: {
            action: 'delete_case_size',
            security: ajax_object.deleteNonce, // Use localized nonce
            term_id: termId,
            taxonomy: taxonomy,
        },
        success: function (response) {
            //console.log(response); // Inspect the response object

            if (response && response.success) {
                alert(response.data.message); // Access the message property
                // Remove the corresponding row from the table without page refresh
                row.remove();
                // Optional: If no rows left, display a "No records found" message
                if (jQuery('table tr').length === 1) {  // Adjust this selector to your actual table structure
                    jQuery('#no-records-row').show();
                }
            } else if (response && response.data && response.data.message) {
                alert(response.data.message); // Fallback for error messages
            } else {
                alert('Unexpected response from server.');
            }
        },
        error: function () {
            alert('Something went wrong!');
        },
    });
    //-----------------Case Size js Start-----------------
});
jQuery(document).ready(function($) {
  // Detect form submission instead of button click
  $('form').on('submit', function() {
    // Disable both Apply buttons immediately after submit starts
    $('#doaction, #doaction2').prop('disabled', true);
  });
   // For WooCommerce Order Update button
  $('#post').on('submit', function() {
    const $btn = $('#publish');
    if ($btn.prop('disabled')) return false;

    $btn.prop('disabled', true).val('Updating...');
  });

  
});
jQuery(document).ready(function($) {
  $('#woocommerce-order-actions button').on('click', function(e) {
    const $btn = $(this);

    // If already disabled, prevent any further action
    if ($btn.prop('disabled')) {
      e.preventDefault();
      return false;
    }

    // Defer disabling slightly to let WooCommerce JS handlers finish
    setTimeout(function() {
      $btn.prop('disabled', true);
      $btn.html('Processing...');
    }, 50); // Let WooCommerce action fire first
  });
});
