jQuery(document).ready(function ($) {
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