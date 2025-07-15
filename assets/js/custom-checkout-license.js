let attempts = 0;
const maxAttempts = 20; // Maximum attempts to retry

// Function to wait for the billing fields container
function waitForBillingFieldsContainer() {
    const billingFieldsContainer = document.querySelector('#billing');
    attempts++;

    if (!billingFieldsContainer && attempts < maxAttempts) {
        console.warn(`Billing fields container not found, attempt ${attempts}. Retrying...`);
        setTimeout(waitForBillingFieldsContainer, 500); // Retry every 500ms
    } else if (billingFieldsContainer) {
        //console.log('Billing fields container found.');
        initializeLicenseField(billingFieldsContainer);
        waitForPlaceOrderButton();
    } else {
        console.error('Billing fields container not found after maximum attempts.');
    }
}

// Function to initialize the license field
function initializeLicenseField(billingFieldsContainer) {
    const wrapperDiv = document.createElement('div');
    wrapperDiv.className = 'wc-block-components-address-form__license wc-block-components-license-input';

    const containerDivFirst = document.createElement('div');
    containerDivFirst.className = 'wc-blocks-components-select';

    const containerDivSecond = document.createElement('div');
    containerDivSecond.className = 'wc-blocks-components-select__container';

    // Create the label
    const customFieldLabel = document.createElement('label');
    customFieldLabel.className = 'wc-blocks-components-select__label';
    customFieldLabel.setAttribute('for', 'select-your-license');
    customFieldLabel.textContent = 'License';
    containerDivSecond.appendChild(customFieldLabel);

    const customFieldSelect = document.createElement('select');
    customFieldSelect.id = 'license';
    customFieldSelect.name = 'license';
    customFieldSelect.className = 'wc-blocks-components-select__select';
    customFieldSelect.setAttribute('aria-required', 'true');
    customFieldSelect.required = true;

    const defaultOption = document.createElement('option');
    defaultOption.value = '';
    defaultOption.selected = true;
    defaultOption.textContent = 'Select your license';
    customFieldSelect.appendChild(defaultOption);

    const svgIcon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svgIcon.setAttribute('viewBox', '0 0 24 24');
    svgIcon.setAttribute('width', '24');
    svgIcon.setAttribute('height', '24');

    const svgPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    svgPath.setAttribute('d', 'M17.5 11.6L12 16l-5.5-4.4.9-1.2L12 14l4.5-3.6 1 1.2z');
    svgIcon.appendChild(svgPath);

    containerDivSecond.appendChild(customFieldSelect);
    containerDivSecond.appendChild(svgIcon);
    containerDivFirst.appendChild(containerDivSecond);
    wrapperDiv.appendChild(containerDivFirst);
    billingFieldsContainer.appendChild(wrapperDiv);

    customFieldSelect.addEventListener('change', toggleErrorMessage);

    // Attempt to prepopulate licenses if an email is available
    const emailField = document.querySelector('#email');
    if (emailField) {
       emailField.addEventListener('change', handleEmailChange);
       populateLicenseFromUser(emailField.value.trim());
    }
}

// Function to toggle error messages and update button state
function toggleErrorMessage() {
    const customFieldInput = document.querySelector('#license');
    const placeOrderButton = document.querySelector('.wc-block-components-checkout-place-order-button');
    const fieldWrapper = customFieldInput.closest('.wc-block-components-address-form__license');
    let errorDiv = fieldWrapper.querySelector('.wc-block-components-validation-error');

    const licenseValue = customFieldInput.value;

    if (!licenseValue) {
        fieldWrapper.classList.add('has-error');
        if (!errorDiv) {
            errorDiv = document.createElement('div');
            errorDiv.className = 'wc-block-components-validation-error';
            errorDiv.setAttribute('role', 'alert');
            errorDiv.innerHTML = `
                <p>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="-2 -2 24 24" width="24" height="24" aria-hidden="true" focusable="false">
                        <path d="M10 2c4.42 0 8 3.58 8 8s-3.58 8-8 8-8-3.58-8-8 3.58-8 8-8zm1.13 9.38l.35-6.46H8.52l.35 6.46h2.26zm-.09 3.36c.24-.23.37-.55.37-.96 0-.42-.12-.74-.36-.97s-.59-.35-1.06-.35-.82.12-1.07.35-.37.55-.37.97c0 .41.13.73.38.96.26.23.61.34 1.06.34s.8-.11 1.05-.34z"></path>
                    </svg>
                    License field is required.
                </p>`;
            fieldWrapper.appendChild(errorDiv);
        }
        placeOrderButton.disabled = true;
    } else {
        fieldWrapper.classList.remove('has-error');
        if (errorDiv) {
            errorDiv.remove();
        }
        placeOrderButton.disabled = false;
    }
}

// Function to populate license options based on user email
function populateLicenseFromUser(email) {
    const placeOrderButton = document.querySelector('.wc-block-components-checkout-place-order-button');
    if (!email) return;

    fetch(licenseData.getApiUrl + '?email=' + encodeURIComponent(email))
        .then(response => {
            if (!response.ok) throw new Error('Network response was not ok');
            return response.json();
        })
        .then(data => {
            const licenseSelect = document.getElementById('license');
            if (data.status === 'success' && Array.isArray(data.licenses) && licenseSelect) {
                licenseSelect.innerHTML = '';
                const placeholderOption = document.createElement('option');
                placeholderOption.value = '';
                placeholderOption.selected = true;
                placeholderOption.textContent = 'Select your license';
                licenseSelect.appendChild(placeholderOption);

                data.licenses.forEach(license => {
                    const option = document.createElement('option');
                    option.value = license;
                    option.textContent = license;
                    licenseSelect.appendChild(option);
                });
            } else {
                console.warn('No licenses available.');
                placeOrderButton.disabled = true;
            }
            toggleErrorMessage();
        })
        .catch(error => console.error('Error fetching licenses:', error));
}

// Function to handle email field changes
function handleEmailChange(event) {
    const emailValue = event.target.value.trim();
    if (emailValue) populateLicenseFromUser(emailValue);
}

// Function to wait for the Place Order button
function waitForPlaceOrderButton() {
    const placeOrderButton = document.querySelector('.wc-block-components-checkout-place-order-button');
    if (placeOrderButton) {
        placeOrderButton.addEventListener('click', toggleErrorMessage);
    } else {
        setTimeout(waitForPlaceOrderButton, 500);
    }
}

// Start polling when the DOM is loaded
//document.addEventListener('DOMContentLoaded', waitForBillingFieldsContainer);
  
jQuery(document).ready(function($) {
     // Initialize Select2 for destination field 
        $('#destination').select2({ 
            placeholder: 'Search for a destination...', 
            allowClear: true, 
            width: '100%',
        }); 
         // Add this to your custom-checkout-license.js file

 
    
    // Handle destination change
    $(document).on('change', '#destination', function() {
        var destinationId = $(this).val();
        var $salesRepField = $('#sales_rep_id');

        // Skip AJAX if user has all destinations and all sales reps (already preloaded)
        if (window.salesRepCheckoutData.allDestination === 'yes' && 
            window.salesRepCheckoutData.allSalesRep === 'yes') {
            console.log('Skipping AJAX - sales reps already preloaded');
            return;
        }
        
        
        if (destinationId) {
            // Show loading state
            $salesRepField.prop('disabled', true);
            $salesRepField.html('<option value="">Loading sales representatives...</option>');
            
            // Make AJAX call to get sales reps for this destination
            $.ajax({
                url: licenseData.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_sales_reps_by_destination',
                    destination_id: destinationId,
                    nonce: licenseData.nonce
                },
                success: function(response) {
                    if (response.success && response.data.sales_reps) {
                        // Clear the dropdown
                        $salesRepField.empty();
                        
                        // Add default option
                        $salesRepField.append('<option value="">Select a sales representative...</option>');
                        
                        // Add sales rep options
                        $.each(response.data.sales_reps, function(repId, repName) {
                            $salesRepField.append('<option value="' + repId + '">' + repName + '</option>');
                        });
                        
                        // Enable the field
                        $salesRepField.prop('disabled', false);
                        
                        // Update description
                        $salesRepField.closest('.form-row').find('small').text('Select your assigned sales representative for this destination.');
                        
                    } else {
                        // No sales reps found
                        $salesRepField.html('<option value="">No sales representatives available</option>');
                        $salesRepField.prop('disabled', true);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error loading sales reps:', error);
                    $salesRepField.html('<option value="">Error loading sales representatives</option>');
                    $salesRepField.prop('disabled', true);
                }
            });
        } else {
            // No destination selected, reset sales rep field
            $salesRepField.html('<option value="">Select destination first</option>');
            $salesRepField.prop('disabled', true);
        }
    });
        // Add validation to checkout process 
        $('form.checkout').on('checkout_place_order', function() { 
            if ($('#destination').val() === '') { 
                // Show error message 
                if (!$('#destination_field .woocommerce-error').length) { 
                    $('#destination_field').append('<div class="woocommerce-error">' +  
                        'Please select a destination' +  
                        '</div>'); 
                } 
                 setButtonState('woocommerce_checkout_place_order', false);
                return false; 
            } 
            return true; 
        }); 
  
  
function handleShipAddressFromLicense(destinationValue) {
   
    if (!destinationValue) {
        alert('Please select atleast one.');
        return;
    }

    const nonce = licenseData.nonce;
    const ajaxUrl = licenseData.ajax_url;

    if (!nonce || !ajaxUrl) {
        alert('AJAX URL or nonce is missing. Please reload the page.');
        return;
    }

    const data = {
        action: 'ship_destination_from_flourish',
        nonce: nonce,
        destination: destinationValue,
    };
 
    $.ajax({
        url: licenseData.ajax_url,
        method: 'POST',
        data: data, 
        beforeSend: function () {
            // Disable the submit button and show a loading message before making the request
            setButtonState('woocommerce_checkout_place_order', false); 
            $('#loading_overlay').show(); // Show loading message or spinner
        },
        success: function (response) {  
            if (response.success) {
                 const destination = response.data.data; 
                  const billing = response.data.data.billing; 
                $('#billing_company').val(destination.name || ''); 
                $('#license').val(destination.license_number || ''); 
                $('#billing_country').val(billing.country || '');  
                $('#billing_address_1').val(billing.address_line_1 || '');   
                $('#billing_address_2').val(billing.address_line_2 || '');   
                $('#billing_city').val(billing.city || '');  
                $('#billing_state').val(billing.state || '');
                $('#billing_postcode').val(billing.zip_code || ''); 
                $('#billing_phone').val(destination.company_phone_number || '');
                  
               
                $('#shipping_company').val(destination.name || '');
                $('#shipping_country').val(destination.country || '');
                $('#shipping_address_1').val(destination.address_line_1 || '');
                $('#shipping_address_2').val(destination.address_line_2 || '');
                $('#shipping_city').val(destination.city || '');
                $('#shipping_postcode').val(destination.zip_code || '');
                $('#shipping_state').val(destination.state || '');  
                $('#shipping_phone').val(destination.company_phone_number || ''); 
                setButtonState('woocommerce_checkout_place_order', true);               
            } else {
                $('#loading_overlay').hide();
                alert(response.data.message || 'No destination found.');
                setButtonState('woocommerce_checkout_place_order', false); 

            }
        },
        error: function () { 
            $('#loading_overlay').hide();
            alert('An error occurred while processing the request.');            
            setButtonState('woocommerce_checkout_place_order', false);
        }, 
        complete: function () {
            // Hide the loading message after the request completes
            $('#loading_overlay').hide();
            $('.checkout-inline-error-message').hide();
            $('.woocommerce-error').hide();
            // Additional jQuery code for form validation styles
            // Apply green border for valid fields
            $('.woocommerce form .form-row.woocommerce-invalid input.input-text').css('border-color', '#6dc22e');
            // Apply red color to labels for invalid fields
            $('.woocommerce form .form-row.woocommerce-invalid label').css('color', '#373737');

            
        }
    });
}  

// Trigger this function when the license dropdown value changes
$('#destination').on('change', function () {
    const selectedLicense = $(this).val();
    if (selectedLicense) {
        handleShipAddressFromLicense(selectedLicense);
    } else {
        alert('Please select a destination name.');
    }
});

function setButtonState(buttonName, isEnabled) {
    console.log(buttonName+isEnabled);
    $(`button[name="${buttonName}"]`).prop('disabled', !isEnabled);
}
 
 

});
     