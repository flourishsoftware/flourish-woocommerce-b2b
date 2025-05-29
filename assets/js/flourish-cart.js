jQuery(document).ready(function ($) {

    var atc_button = $("button.single_add_to_cart_button");
    // Check if button exists before binding events
    if (atc_button.length) {
        //prevent multiple clicks on add-to-cart
        $("form.cart").submit(function() {
            //disable subsequent submits
            $(this).submit(function() {
                return false;
            });
            //show button as disabled
            if (!atc_button.hasClass('disabled')) {
                atc_button.addClass('disabled').text("Please wait...");
            }
            return true;
        });
    }

    // Check if there's a toast message in sessionStorage
    const message = sessionStorage.getItem('toastMessage');
    if (message) {
        // Remove the message from sessionStorage to avoid showing it again
        sessionStorage.removeItem('toastMessage');
        
        // Show the toast after 10 seconds
        setTimeout(function () {
            showToast(message, 'success');
        }, 500); // 10,000 milliseconds = 10 seconds
    }

    function checkCartReservation() {        
        if (typeof stockAvailability !== 'undefined' && stockAvailability.ajax_url) {
            $.ajax({
                url: stockAvailability.ajax_url, 
                type: 'POST',
                data: {
                    action: 'mc_cleanup_cart'
                },
                success: function (response) {
                    if (response.success) {
                        location.reload();
                    }
                },
                error: function () {
                    console.log('Error checking cart reservation.');
                }
            });
        } 
    }
    
    // Check reservation every 5 sec
    setInterval(checkCartReservation, 5000);
    
     

    // Handle Save Cart submission
    $(document).off('click', '#mc-save-cart-submit').on('click', '#mc-save-cart-submit', function (e) {
        e.preventDefault();
        const button = $(this);
        const cartName = $('#mc-cart-name').val().trim();
        const loadingText = '<span class="frh-loader"></span> Saving...';

        if (!cartName) {
            showToast('Please enter a cart name.', 'warning');
            return;
        }

         // Disable button and show loading text
        button.prop('disabled', true).addClass('mc-btn-loading').html(loadingText);
        
        // AJAX request to save the cart
        $.post(stockAvailability.ajax_url, {
            action: 'mc_save_cart',
            cart_name: cartName,
        }).done(function (response) {
            // Store the message in sessionStorage
            sessionStorage.setItem('toastMessage', response.data.message);
           
            if (response.success) {                
                location.reload(); // Reload the page to show updated saved carts
            }
        }).fail(function () {
            console.log(response.data.message);
            showToast('An error occurred while saving the cart.', 'error');
        }).always(function () {
            // Restore button after AJAX completes
            button.prop('disabled', false).removeClass('mc-btn-loading').text('Save');
        });
    });

    // Handle load cart button script
    $(document).off('click', '.mc-load-cart-btn').on('click', '.mc-load-cart-btn', function (e) {
        e.preventDefault();
        const cartName = $(this).data('cart-name');
 
                $.post(stockAvailability.ajax_url, {
                    action: 'mc_load_saved_cart',
                    cart_name: cartName,
                }).done(function (response) {
                    if (response.success) {
                        window.location.href = response.data.redirect_url;
                    } else {
                        showToast(response.data.message, 'error');
                    }
                }).fail(function () {
                    showToast('An error occurred while loading the cart.', 'error');
                });
           
       
    });

    // Handle hide/show view saved cart list script with AJAX action
    $('#mc-view-saved-carts-btn').off('click').on('click', function () {
        const savedCartsList = $('#mc-saved-carts-list');
        const button = $(this);
        const loadingText = "<span class='frh-loader'></span> Just a moment...we're fetching your saved carts!";
    
        if (savedCartsList.is(':hidden')) {
            
            button.prop('disabled', true).addClass('mc-btn-loading').html(loadingText);
            
            $.post(stockAvailability.ajax_url, { action: 'mc_pre_toggle_saved_carts' }, function (response) {
                if (response.success) {
                    button.prop('disabled', false).removeClass('mc-btn-loading').text('Click to hide your Saved Carts'); // Restore button text
            
                    if (response.data.unsaved_items) {
                        if (confirm("Extra items (including variations) exist in your cart that are not in your saved cart. Click 'OK' to remove them or 'Cancel' to update your cart manually.")) {
                            $.post(stockAvailability.ajax_url, { action: 'mc_clear_current_cart', confirmed: 'yes' }, function (clearResponse) {
                                if (clearResponse.success) {
                                    savedCartsList.stop(true, true).slideDown(300);
                                    $('#mc-view-saved-carts-btn').text('Click to hide your Saved Carts');
                                    $('.woocommerce-cart-form, .cart_totals, .woocommerce-message, .woocommerce-error, .woocommerce-info').remove();
                                    refresh_mini_cart();
                                  
                                    // location.reload();
                                } else {
                                    alert(clearResponse.data.message);
                                }
                            }).fail(function () {
                                alert("An error occurred while clearing the cart.");
                            });
                        } else {
                            alert("Cancelled. You can either save the cart items or update the items in your existing cart.");
                        }
                    } else {
                        savedCartsList.stop(true, true).slideDown(300);
    
                        $.post(stockAvailability.ajax_url, { action: 'mc_clear_current_cart' }, function (clearResponse) {
                            if (clearResponse.success) {
                                savedCartsList.stop(true, true).slideDown(300);
                                // button.prop('disabled', false).text('Click to hide your Saved Carts');
                                $('.woocommerce-cart-form, .cart_totals, .woocommerce-message, .woocommerce-error, .woocommerce-info').remove();
                                location.reload();
                            }
                        }).fail(function () {
                            alert("An error occurred while clearing the cart.");
                        });
                    }
                } else {
                    alert("Unable to toggle saved carts at the moment. Please try again.");
                }
            }).fail(function () {
                button.text('Click to view your Saved Carts');
                alert("An error occurred while toggling the saved carts view.");
            });
    
        } else {
            savedCartsList.stop(true, true).slideUp(300);
            button.text('Click to view your Saved Carts');
        }
    });

    // To refresh of the mini cart automatically
    function refresh_mini_cart() {
        $.ajax({
            url: wc_cart_fragments_params.wc_ajax_url.toString().replace('%%endpoint%%', 'get_refreshed_fragments'),
            type: 'POST',
            success: function (response) {
                if (response && response.fragments) {
                    $.each(response.fragments, function (key, value) {
                        $(key).replaceWith(value);
                    });
                }
            }
        });
    }
   
    // Prevent multiple clicks on "Add to Cart" button
    $(document).on("click", ".add_to_cart_button", function (e) {
        var button = $(this);

        // If button is already disabled, prevent further action
        if (button.hasClass("disabled")) {
            e.preventDefault();
            return false;
        }

        // Disable button and change text
        button.addClass("disabled").text("Adding...");

        // Re-enable button after 3 seconds (optional)
        setTimeout(function () {
            button.removeClass("disabled").text("Add to Cart");
        }, 3000); // Adjust timeout as needed
    });

    // Show the popup
    $(document).on('click', '#mc-save-cart-btn', function () {
        console.log("Open Save Cart Modal");
        $('#mc-save-cart-modal').fadeIn();
        $('#mc-modal-overlay').fadeIn();
    });

    // Close the popup
    $(document).on('click', '#mc-close-cart-modal, #mc-modal-overlay', function () {
        $('#mc-save-cart-modal').fadeOut();
        $('#mc-modal-overlay').fadeOut();
    });
    jQuery(document).ready(function($) {

    //------------------------- Save to cart js::Start --------------------
    // Ensure the update cart button is enabled on page load
    const updateCartBtn = $('button[name="update_cart"]');
    if (updateCartBtn.length && updateCartBtn.prop('disabled')) {
        updateCartBtn.prop('disabled', false); // Enable the button
    }
        
    // Listen for the WooCommerce 'updated_cart_totals' event
    $(document.body).on('updated_cart_totals', function() {
        // Ensure the 'Update cart' button remains enabled after the cart is updated
    if (updateCartBtn.length && updateCartBtn.prop('disabled')) {
        updateCartBtn.prop('disabled', false); // Enable the button if it's still disabled
    }
        // Custom behavior for the saved cart button
        const mcUpdateSavedCartBtn = $('#mc-update-saved-cart');
        if (mcUpdateSavedCartBtn.length) {
            const cartName = mcUpdateSavedCartBtn.data('cart-name');           
            if (cartName) {
                // Trigger the AJAX request to update the saved cart
                $.post(stockAvailability.ajax_url, {
                    action: 'mc_update_saved_cart_ajax',
                    cart_name: cartName,
                }).done(function(response) {
                    // Store the message in sessionStorage for display after reload
                    sessionStorage.setItem('toastMessage', response.data.message);
                    if (response.success) {                         
                    //location.reload(); // Reload to show updated cart state
                    } else {
                        showToast(response.data.message || 'Failed to update the cart.', 'error');
                    }
                }).fail(function() {
                    showToast('An error occurred while updating the cart.', 'error');
                });
            } else {
                showToast('No cart name available for updating.', 'error');
            }
        }  
    });

    // Optionally, trigger a reload of the cart when the page is loaded and the session contains a toast message
    if (sessionStorage.getItem('toastMessage')) {
        const message = sessionStorage.getItem('toastMessage');
        showToast(message, 'success');
        sessionStorage.removeItem('toastMessage');
    }
});
   
    // Handle Delete Cart button
    $(document).off('click', '.mc-delete-cart-btn').on('click', '.mc-delete-cart-btn', function (e) {
        e.preventDefault();

        const cartName = $(this).data('cart-name');
        if (!confirm('Are you sure you want to delete this cart?')) {
            return;
        }

        // AJAX request to delete the saved cart
        $.post(stockAvailability.ajax_url, {
            action: 'mc_delete_saved_cart',
            cart_name: cartName,
        }).done(function (response) {
            // Store the message in sessionStorage
            sessionStorage.setItem('toastMessage', response.data.message);
            if (response.success) {
                location.reload(); // Reload the page to show updated saved carts
            }
        }).fail(function () {
            showToast('An error occurred while deleting the cart.', 'error');
        });
    });
    //----------------save to cart js end-------------------

    //-----------------add to cart stock validation js start-----------------
    // Override WooCommerce's default add-to-cart behavior

    // Monitor changes to the quantity input field
    $(document).on('change input', 'form.cart .quantity input.qty', function () {
        var $input = $(this);
        var quantity = parseInt($input.val(), 10); // Get the entered quantity
        var alertShown = $input.data('alert-shown') || false;
        var $addToCartButton = $('.single_add_to_cart_button'); // Select the add to cart button
    
        // Validate the quantity
        if (isNaN(quantity) || quantity <= 0) {
            if (!alertShown) { // Check if the alert has already been shown
                showToast('This field cannot be empty.', 'info');
                $input.data('alert-shown', true); // Set the alert-shown flag to true
            }
            $addToCartButton.prop('disabled', true); // Disable the add to cart button
            return false; // Prevent the form submission if the quantity is invalid
        } else {
            $input.data('alert-shown', false); // Reset the alert-shown flag if quantity is valid
            $addToCartButton.prop('disabled', false); // Enable the add to cart button
        }
    });
    
    // Restrict JS Refresh 'Resubmit a form' in browser
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }
    //-----------------add to cart stock validation js end-----------------

    function updateTimers() {
        $('.reservation-timer').each(function () {
            const timerElement = $(this);
            let remainingTime = parseInt(timerElement.data('remaining-time'), 10);

            if (remainingTime > 0) {
                // Decrease remaining time and update the timer display
                remainingTime--;
                timerElement.data('remaining-time', remainingTime);

                const minutes = Math.floor(remainingTime / 60);
                const seconds = remainingTime % 60;

                // Display the time in "MM:SS" format
                timerElement.text(`${minutes}:${seconds < 10 ? '0' : ''}${seconds}`);
            } else {
                // Time expired
                timerElement.text('Expired');
            }
        });
    }

    // Update timers every second
    setInterval(updateTimers, 1000);
    let isCartReservationCheckRunning = false; // Lock to prevent overlapping calls

    function CartReservationTimeout() {
        console.log("caling");
        if (isCartReservationCheckRunning) {           
            return; // Skip if the previous call is still running
        }

        isCartReservationCheckRunning = true; // Set the lock
        $.ajax({
            url: stockAvailability.ajax_url,
            type: 'POST',
            data: {
                action: 'cart_cleanup_item_reservation_timeout',
                _ajax_nonce: stockAvailability.nonce, // Include a nonce for validation
            },
            success: function (response) {
                 console.log('AJAX Success:', response);
                 if (response.data.message.includes('removed')) {
                    location.reload(); // Reflect changes
                }
            },
            error: function (xhr, status, error) {
                console.error('AJAX Error:', status, error);
            },
            complete: function () {
                isCartReservationCheckRunning = false; // Release the lock
                  console.log('AJAX call completed.');
            },
        });
    }

    // Set the interval for the AJAX call (every 6 sec)
    if (!window.isCartReservationIntervalInitialized) {
        window.isCartReservationIntervalInitialized = true;        
        setInterval(CartReservationTimeout, 1000); // 6 sec
    }
});
jQuery(document).on('click', '.remove', function(e) {
    e.preventDefault();
 
    let productId = jQuery(this).data('product_id'); 
    let cartItemKey = new URL(jQuery(this).attr('href')).searchParams.get('remove_item'); 
    let nonce = stockAvailability.nonce;
 
    jQuery.ajax({
        url: stockAvailability.ajax_url,
        type: 'POST',
        data: {
            action: 'restore_stock_on_remove_cart',
            product_id: productId,
            cart_item_key: cartItemKey,
            nonce: nonce
        },
        success: function(response) {
            if (response.success) {                
                window.location.reload();
            } else {
                alert(response.data.error);
            }
        },
        error: function() {
            alert('Failed to remove item. Please try again.');
        }
    });
});


// Function to create and show toast alert
function showToast(message, type = 'info', duration = 3000) {
    // Create toast container if it doesn't exist
    if (!document.querySelector('.toast-container')) {
        let container = document.createElement('div');
        container.classList.add('toast-container');
        document.body.appendChild(container);
    }

    // Create toast element
    const toast = document.createElement('div');
    toast.classList.add('toast', `toast-${type}`);

    // Create icon (can customize or replace with icon libraries)
    const icon = document.createElement('span');
    icon.classList.add('toast-icon');
    if (type === 'info') {
        icon.innerHTML = 'ℹ️'; // Info Icon
    } else if (type === 'success') {
        icon.innerHTML = '✔️'; // Success Icon
    } else if (type === 'warning') {
        icon.innerHTML = '⚠️'; // Warning Icon
    } else if (type === 'error') {
        icon.innerHTML = '❌'; // Error Icon
    }

    // Message content
    const messageText = document.createElement('span');
    messageText.textContent = message;

    // Append icon and message to toast
    toast.appendChild(icon);
    toast.appendChild(messageText);

    // Append toast to the toast container
    document.querySelector('.toast-container').appendChild(toast);

    // Show the toast with animation
    setTimeout(() => {
        toast.classList.add('show');
    }, 10);

    // Auto-remove the toast after the duration
    setTimeout(() => {
        toast.classList.remove('show');
        // After fade-out, remove from DOM
        setTimeout(() => toast.remove(), 300);
    }, duration);
}
