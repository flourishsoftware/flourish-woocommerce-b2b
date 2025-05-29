jQuery(function ($) {
    const user_id = $('#user_id').val(); // Make sure this hidden field exists
     const $selectedDestinationsDisplay = $('#selected_destinations_display');
    const $selectedDestinationIds = $('#destination_ids'); // Updated to match your HTML
     
    // Check if we have existing destinations (derived from the hidden field's value)
    const hasDestinations = $selectedDestinationIds.val() !== '';

     

    // Check if there are destinations on page load
    if (!hasDestinations) {
        // Only show the Add button with special text initially
        $('#add-destination').on('click', handleAddDestination);  
    } else {
        // If destinations exist, setup normal button handlers
        $('#add-destination').on('click', handleAddDestination); 
    }

    // Handler for Add/Assign Destinations
    function handleAddDestination() {
        // Get selected destination IDs from the hidden field
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
                    // Reload the page to reflect changes properly
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
 

    // Add user_id hidden field if not present
    if ($('#user_id').length === 0) {
        // Try to get the user ID from the URL
        const urlParams = new URLSearchParams(window.location.search);
        const urlUserId = urlParams.get('user_id');
        
        if (urlUserId) {
            $('form').append('<input type="hidden" id="user_id" name="user_id" value="' + urlUserId + '">');
        }
    }
    
    // Make sure licenseData object exists
    if (typeof licenseData === 'undefined') {
        window.licenseData = {
            ajax_url: ajaxurl, // WordPress global
            nonce: $('#_wpnonce').val() // Try to get from standard WP admin form
        };
    } 
   

// Handle the "All Destinations" toggle button
$('#all_destinations_toggle').on('click', function() {
    if (!$(this).hasClass('active')) {
        // Show confirmation dialog
        if (confirm('Are you sure you want to select all destinations? This will clear your current destination selections.')) {
            // Show loader
            const $button = $(this);
            $button.html('<span class="loader"></span> Processing...');
            $button.prop('disabled', true);
            $('#specific_destinations_toggle').prop('disabled', true);
            $('#specific_destinations_toggle').html("Choose Specific Destinations");
              // Update toggle states
            $button.addClass('active');
            $('#specific_destinations_toggle').removeClass('active');
            
            // Update the hidden meta field for destination selection type
            updateDestinationSelectionMeta('yes');
            $('#destination_section').addClass('hidden-section').removeClass('visible-section');
        }
    }
});
// Handle the "Choose Specific" toggle button
$('#specific_destinations_toggle').on('click', function() {
    if (!$(this).hasClass('active')) {
        // Show confirmation dialog
        if (confirm('Are you sure you want to select specific destinations?')) {
            // Show loader
            const $button = $(this);
            const buttonText = $button.html();
            $button.html('<span class="loader"></span> Processing...');
            $button.prop('disabled', true);
            $('#all_destinations_toggle').prop('disabled', true);
            $('#all_destinations_toggle').html("All Destinations");
            
             
                // Update toggle states
                $button.addClass('active');
                $('#all_destinations_toggle').removeClass('active');
                
                // Update the hidden meta field for destination selection type
               updateDestinationSelectionMeta('no');
              //  $('#destination_section').addClass('visible-section').removeClass('hidden-section');
                  
        }
    }
});
     

    // Function to update the destination selection meta value via AJAX
    function updateDestinationSelectionMeta(value) {  
        // Send AJAX request to update the meta value
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

});