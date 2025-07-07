jQuery(function ($) {
    const user_id = $('#user_id').val(); // Get user ID from hidden field
    const $selectedDestinationsDisplay = $('#selected_destinations_display');
    const $selectedDestinationIds = $('#destination_ids');
    
    // Check if we have existing destinations
    const hasDestinations = $selectedDestinationIds.val() !== '';

    // Ensure licenseData is available
    if (typeof licenseData === 'undefined') {
        window.licenseData = {
            ajax_url: ajaxurl,
            nonce: $('#_wpnonce').val() || 'fallback_nonce'
        };
    }

    // Initialize destination management
    if (!hasDestinations) {
        $('#add-destination').on('click', handleAddDestination);
    } else {
        $('#add-destination').on('click', handleAddDestination);
    }
     // Toggle dropdown
                    $(document).on('click', '.multiselect-display', function(e) {
                        e.stopPropagation();
                        var $wrapper = $(this).closest('.custom-multiselect-wrapper');
                        var $dropdown = $wrapper.find('.multiselect-dropdown');
                        var destination = $(this).data('destination');
                        
                        // Close other dropdowns
                        $('.multiselect-display').removeClass('active');
                        $('.multiselect-dropdown').hide();
                        
                        // Toggle current dropdown
                        $(this).addClass('active');
                        $dropdown.show();
                    });

                    // Close dropdown when clicking outside
                    $(document).on('click', function(e) {
                        if (!$(e.target).closest('.custom-multiselect-wrapper').length) {
                            $('.multiselect-display').removeClass('active');
                            $('.multiselect-dropdown').hide();
                        }
                    });

                    // Handle option selection
                    $(document).on('change', '.option-checkbox', function() {
                        var destination = $(this).data('destination');
                        updateDisplay(destination);
                        updateSelectAllState(destination);
                    });

                    // Handle select all
                    $(document).on('change', '.select-all-checkbox', function() {
                        var destination = $(this).data('destination');
                        var isChecked = $(this).is(':checked');
                        
                        $('.option-checkbox[data-destination="' + destination + '"]').prop('checked', isChecked);
                        updateDisplay(destination);
                    });

                    // Update display text
                    function updateDisplay(destination) {
                        var $display = $('.multiselect-display[data-destination="' + destination + '"]');
                        var $selectedItems = $display.find('.selected-items');
                        var checkedCount = $('.option-checkbox[data-destination="' + destination + '"]:checked').length;
                        
                        if (checkedCount === 0) {
                            $selectedItems.html('<span class="placeholder"> Select sales representatives...</span>');
                        } else {
                            $selectedItems.html('<span class="selected-count">' + checkedCount + ' selected</span>');
                        }
                    }

                    // Update select all checkbox state
                    function updateSelectAllState(destination) {
                        var $selectAll = $('.select-all-checkbox[data-destination="' + destination + '"]');
                        var $options = $('.option-checkbox[data-destination="' + destination + '"]');
                        var checkedCount = $options.filter(':checked').length;
                        var totalCount = $options.length;

                        if (checkedCount === 0) {
                            $selectAll.prop('indeterminate', false);
                            $selectAll.prop('checked', false);
                        } else if (checkedCount === totalCount) {
                            $selectAll.prop('indeterminate', false);
                            $selectAll.prop('checked', true);
                        } else {
                            $selectAll.prop('indeterminate', true);
                            $selectAll.prop('checked', false);
                        }
                    }

                    // Initialize states on page load
                    $('.custom-multiselect-wrapper').each(function() {
                        var destination = $(this).find('.multiselect-display').data('destination');
                        updateDisplay(destination);
                        updateSelectAllState(destination);
                    });
    // Handler for Add/Assign Destinations
    function handleAddDestination() {
        const selectedDestinations = $selectedDestinationIds.val().split(',').filter(id => id.trim() !== '');
        
        if (!confirm('Proceed with the update? This cannot be undone.')) {
            return;
        }

        const data = {
            action: 'update_user_destinations',
            nonce: licenseData.nonce,
            destinations: selectedDestinations,
            action_type: 'add',
            user_id: user_id
        };

        $.ajax({
            url: licenseData.ajax_url,
            method: 'POST',
            data: data,
            success: function (response) {
                if (response.success) {
                    alert(response.data.message);
                    window.location.reload();
                } else {
                    alert(response.data.message || 'An error occurred');
                }
            },
            error: function () {
                alert('An error occurred while processing the request.');
            }
        });
    }

    // Handle the "All Destinations" toggle button
    $('#all_destinations_toggle').on('click', function() {
        if (!$(this).hasClass('active')) {
            if (confirm('Are you sure you want to select all destinations? This will clear your current destination selections.')) {
                const $button = $(this);
                $button.html('<span class="loader"></span> Processing...');
                $button.prop('disabled', true);
                $('#specific_destinations_toggle').prop('disabled', true);
                $('#specific_destinations_toggle').html("Choose Specific Destinations");
                
                $button.addClass('active');
                $('#specific_destinations_toggle').removeClass('active');
                
                updateDestinationSelectionMeta('yes');
                $('#destination_section').addClass('hidden-section').removeClass('visible-section');
            }
        }
    });

    // Handle the "Choose Specific" toggle button
    $('#specific_destinations_toggle').on('click', function() {
        if (!$(this).hasClass('active')) {
            if (confirm('Are you sure you want to select specific destinations?')) {
                const $button = $(this);
                const buttonText = $button.html();
                $button.html('<span class="loader"></span> Processing...');
                $button.prop('disabled', true);
                $('#all_destinations_toggle').prop('disabled', true);
                $('#all_destinations_toggle').html("All Destinations");
                
                $button.addClass('active');
                $('#all_destinations_toggle').removeClass('active');
                
                updateDestinationSelectionMeta('no');
            }
        }
    });

    // Function to update the destination selection meta value via AJAX
    function updateDestinationSelectionMeta(value) {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'update_destination_selection_toggle',
                user_id: user_id,
                all_destination: value,
                nonce: licenseData.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    window.location.reload();
                } else {
                    console.error('Failed to update destination selection type');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', error);
            }
        });
    }

    // Initialize Select2 with search
    $('#destination_select').select2({
        placeholder: 'Select destinations...',
        allowClear: true,
        width: '40%'
    });
    
    // Add selected item
    $('#destination_select').on('change', function() {
        var selectedId = $(this).val();
        
        if (!selectedId) return;
        
        var selectedText = $(this).find('option:selected').text();
        var existingIds = $('#destination_ids').val() ? $('#destination_ids').val().split(',') : [];
        
        // Don't add duplicates
        if (existingIds.includes(selectedId)) {
            $(this).val('').trigger('change.select2');
            return;
        }
        
        // Add to display
        $('.no-selections').hide();
        
        var newItem = $('<span class="selected-item" data-id="' + selectedId + '">' + 
                       '<span class="item-text">' + selectedText + '</span>' + 
                       '<a href="#" class="remove-item" title="Remove">×</a>' + 
                       '</span>');
        
        $('#selected_destinations_display').append(newItem);
        $('#add-destination').prop('disabled', false);
        
        // Update hidden fields
        existingIds.push(selectedId);
        $('#destination_ids').val(existingIds.join(','));
        
        var existingTexts = $('#destination_texts').val() ? $('#destination_texts').val().split('||') : [];
        existingTexts.push(selectedText);
        $('#destination_texts').val(existingTexts.join('||'));
        
        // Reset select
        $(this).val('').trigger('change.select2');
    });
    
    // Remove selected item
    $('#selected_destinations_display').on('click', '.remove-item', function(e) {
        e.preventDefault();
        
        var item = $(this).closest('.selected-item');
        var itemId = item.data('id');
        var itemText = item.find('.item-text').text();
        
        // Update hidden fields
        var existingIds = $('#destination_ids').val() ? $('#destination_ids').val().split(',') : [];
        var filteredIds = existingIds.filter(function(id) {
            return id !== itemId.toString();
        });
        $('#destination_ids').val(filteredIds.join(','));
        
        var existingTexts = $('#destination_texts').val() ? $('#destination_texts').val().split('||') : [];
        var filteredTexts = existingTexts.filter(function(text) {
            return text !== itemText;
        });
        $('#destination_texts').val(filteredTexts.join('||'));
        
        // Remove from display
        item.remove();
        
        // Show placeholder if no items left
        if ($('#selected_destinations_display .selected-item').length === 0) {
            $('#selected_destinations_display').html('<em class="no-selections">No destinations selected</em>');
        }
    });

    // =================== SALES REP MANAGEMENT ===================
     // Sales Rep Management Toggle
    $('#sales_rep_toggle').on('change', function() {
        var showSalesRep = $(this).is(':checked') ? 'yes' : 'no';
        
        $.post(ajaxurl, {
            action: 'toggle_sales_rep_management',
            user_id: user_id,
            show_sales_rep: showSalesRep,
            nonce:  licenseData.nonce
        }, function(response) {
            if (response.success) {
                if (showSalesRep === 'yes') {
                    $('#sales_rep_management_section').show();
                } else {
                    $('#sales_rep_management_section').hide();
                }
                //location.reload();
            } else {
                alert('Error: ' + response.data.message);
            }
        });
    });
    // Sales Rep Management Toggle
    $('#sales_rep_by_destination_toggle, #all_sales_rep_toggle').on('click', function() {
        var isAllSalesRep = $(this).attr('id') === 'all_sales_rep_toggle';
        var allSalesRep = isAllSalesRep ? 'yes' : 'no';
        
        console.log('Toggle clicked:', $(this).attr('id'), 'Setting all_sales_rep to:', allSalesRep);
        
        // Update button states immediately
        $('#sales_rep_by_destination_toggle, #all_sales_rep_toggle').removeClass('active');
        $(this).addClass('active');
        
        // Toggle sections
        if (isAllSalesRep) {
            console.log('Showing all sales rep section');
            $('#sales_rep_by_destination_section').hide();
            $('#all_sales_rep_section').show();
        } else {
            console.log('Showing destination sales rep section');
            $('#all_sales_rep_section').hide();
            $('#sales_rep_by_destination_section').show();
        }
        
        $.post(ajaxurl, {
            action: 'update_sales_rep_selection_toggle',
            user_id: user_id,
            all_sales_rep: allSalesRep,
            nonce: licenseData.nonce
        }, function(response) {
            console.log('Toggle response:', response);
            if (!response.success) {
                alert('Error updating sales rep settings: ' + response.data.message);
                location.reload();
            }
        }).fail(function(xhr, status, error) {
            console.error('Toggle AJAX failed:', error);
        });
    });
   // Select All checkbox functionality
    $('#select_all_sales_reps').on('change', function() {
        var isChecked = $(this).is(':checked');
        $('input[name="sales_rep_checkbox[]"]').prop('checked', isChecked);
        updateSelectedCount();
    });
    
    // Individual checkbox change
    $('input[name="sales_rep_checkbox[]"]').on('change', function() {
        updateSelectedCount();
        
        // Update "Select All" checkbox state
        var totalCheckboxes = $('input[name="sales_rep_checkbox[]"]').length;
        var checkedCheckboxes = $('input[name="sales_rep_checkbox[]"]:checked').length;
        $('#select_all_sales_reps').prop('checked', totalCheckboxes === checkedCheckboxes);
    });
    
    function updateSelectedCount() {
        var selectedCount = $('input[name="sales_rep_checkbox[]"]:checked').length;
        $('#selected_count').text(selectedCount + ' selected');
    }
    
    /// Assign selected sales reps
    $('#assign_all_sales_reps').on('click', function() {
        var selectedReps = [];
        $('input[name="sales_rep_checkbox[]"]:checked').each(function() {
            selectedReps.push($(this).val());
        });
        
        if (selectedReps.length === 0) {
            alert('Please select at least one sales representative.');
            return;
        }
        
        if (!confirm('Are you sure you want to assign the selected sales representatives?')) {
            return;
        }
        
        var $button = $(this);
        var originalText = $button.text();
        $button.text('Processing...').prop('disabled', true);
        
        $.post(ajaxurl, {
            action: 'update_user_sales_reps',
            user_id: user_id,
            selected_sales_reps: selectedReps,
            nonce: licenseData.nonce
        }, function(response) {
            $button.text(originalText).prop('disabled', false);
            
            if (response.success) {
                alert('Selected sales representatives assigned successfully!');
                location.reload();
            } else {
                alert('Error: ' + response.data.message);
            }
        }).fail(function() {
            $button.text(originalText).prop('disabled', false);
            alert('Network error occurred. Please try again.');
        });
    });

    // Assign Destination Sales Reps
    // FIXED: Assign Destination Sales Reps Button Handler
    $('#assign_destination_sales_reps').on('click', function() {
        var destinationSalesReps = {};
        var hasSelections = false;
        
        // Collect all selected checkboxes for each destination
        $('.destination-sales-rep-row').each(function() {
            var destinationId = $(this).data('destination-id');
            var selectedReps = [];
            
            // Find all checked checkboxes for this destination
            $(this).find('.option-checkbox:checked').each(function() {
                selectedReps.push($(this).val());
                hasSelections = true;
            });
            
            // Only add to the object if there are selections
            if (selectedReps.length > 0) {
                destinationSalesReps[destinationId] = selectedReps;
            }
        });
        
        if (!hasSelections) {
            alert('Please select at least one sales representative for a destination.');
            return;
        }
        
        if (!confirm('Are you sure you want to assign the selected sales representatives?')) {
            return;
        }
        
        var $button = $(this);
        var originalText = $button.text();
        $button.text('Processing...').prop('disabled', true);
        
        $.post(ajaxurl, {
            action: 'assign_destination_sales_reps',
            user_id: user_id,
            destination_sales_reps: destinationSalesReps,
            nonce: licenseData.nonce
        }, function(response) {
            $button.text(originalText).prop('disabled', false);
            
            if (response.success) {
                alert('Destination sales reps assigned successfully!');
                location.reload();
            } else {
                alert('Error: ' + response.data.message);
            }
        }).fail(function() {
            $button.text(originalText).prop('disabled', false);
            alert('Network error occurred. Please try again.');
        });
    });
 
    

    
});