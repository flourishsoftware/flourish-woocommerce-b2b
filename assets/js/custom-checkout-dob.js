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
 
        // Create the wrapper div
        const wrapperDiv = document.createElement('div');
        wrapperDiv.className = 'wc-block-components-text-input wc-block-components-address-form__dob is-active';
 
        // Create the custom input field for Date of Birth
        const customFieldInput = document.createElement('input');
        customFieldInput.type = 'date';
        customFieldInput.id = 'dob';
        customFieldInput.name = 'dob';
        customFieldInput.autocapitalize = 'none';
        customFieldInput.autocomplete = 'bday';
        customFieldInput.setAttribute('aria-label', 'Date of Birth');
        customFieldInput.required = true;
        // Create the label for the input
        const customFieldLabel = document.createElement('label');
        customFieldLabel.setAttribute('for', 'dob');
        customFieldLabel.textContent = 'Date of Birth';
        // Append the input and label to the wrapper div
        wrapperDiv.appendChild(customFieldInput);
        wrapperDiv.appendChild(customFieldLabel);
        // Append the wrapper div to the billing fields container
        billingFieldsContainer.appendChild(wrapperDiv);
        populateDOBFromSession();
        // Event listener to handle DOB change
        customFieldInput.addEventListener('change', handleDOBChange);        
        // Add event listeners for  blur,input  and focus events
        customFieldInput.addEventListener('blur', toggleErrorMessage);
        customFieldInput.addEventListener('input', toggleErrorMessage);
        customFieldInput.addEventListener('focus', () => {
            customFieldInput.closest('.wc-block-components-address-form__dob').classList.add('is-active');
        });
 
        // Look for the email field and add change listener
        const emailField = document.querySelector('#email');
        if (emailField) {
            emailField.addEventListener('change', handleEmailChange);
        }
 
        // Initialize the Place Order button logic if needed
        waitForPlaceOrderButton();
    } else {
        console.error('Billing fields container not found after maximum attempts.');
    }
}
 
 
function toggleErrorMessage() {
    const customFieldInput = document.querySelector('#dob');
    const fieldWrapper = customFieldInput.closest('.wc-block-components-address-form__dob');
    const errorDiv = fieldWrapper.querySelector('.wc-block-components-validation-error');
    const placeOrderButton = document.querySelector('.wc-block-components-checkout-place-order-button');
 
    // Calculate the age
    const dobValue = customFieldInput.value;
    const dobDate = new Date(dobValue);
    const today = new Date();
    const age = today.getFullYear() - dobDate.getFullYear();
    const monthDiff = today.getMonth() - dobDate.getMonth();
    const isUnderage = age < 21 || (age === 21 && monthDiff < 0);
 
    if (!dobValue || isUnderage) {
        fieldWrapper.classList.add('has-error');
 
        if (!errorDiv) {
            const newErrorDiv = document.createElement('div');
            newErrorDiv.classList.add('wc-block-components-validation-error');
            newErrorDiv.setAttribute('role', 'alert');
            newErrorDiv.innerHTML = `
                <p>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="-2 -2 24 24" width="24" height="24" aria-hidden="true" focusable="false">
                        <path d="M10 2c4.42 0 8 3.58 8 8s-3.58 8-8 8-8-3.58-8-8 3.58-8 8-8zm1.13 9.38l.35-6.46H8.52l.35 6.46h2.26zm-.09 3.36c.24-.23.37-.55.37-.96 0-.42-.12-.74-.36-.97s-.59-.35-1.06-.35-.82.12-1.07.35-.37.55-.37.97c0 .41.13.73.38.96.26.23.61.34 1.06.34s.8-.11 1.05-.34z"></path>
                    </svg>
                    <span>${!dobValue ? "Please enter a valid Date of Birth" : "You must be at least 21 years old."}</span>
                </p>`;
            fieldWrapper.appendChild(newErrorDiv);
        }
 
        placeOrderButton.disabled = true; // Disable the Place Order button
    } else {
        fieldWrapper.classList.add('is-active');
        fieldWrapper.classList.remove('has-error');
 
        if (errorDiv) {
            errorDiv.remove();
        }
 
        placeOrderButton.disabled = false; // Enable the Place Order button
    }
}

// Function to populate DOB from session on page load
function populateDOBFromSession() {
    const emailField = document.querySelector('#email');
    const dobField = document.querySelector('#dob');
 
    if (emailField && dobField && emailField.value) {
        const email = emailField.value;
         console.log(dobData.getApiUrl,email);
        // Fetch DOB from session using the email
        fetch(dobData.getApiUrl+ '?email=' + encodeURIComponent(email))
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    console.log('DOB fetched:', data.dob);
                    dobField.value = data.dob;  // Set the DOB field's value
                    // Handle the fetched DOB (e.g., pre-fill the DOB field)
                } else {
                    console.error('No DOB found:', data.message);
                }
            })
            .catch(error => {
                console.error('Error fetching DOB:', error);
            });
 
    } else {
        console.warn('Email or DOB field is missing, or email field is empty.');
    }
}
// Function to handle changes in DOB field
function handleDOBChange(event) {
    const dobValue = event.target.value;
    const emailValue = document.querySelector('#email') ? document.querySelector('#email').value : null;
 
    if (dobValue) {
        //console.log('DOB changed:', dobValue);
        sendDOBToServer(dobValue, emailValue);
    }
    else
    {
            toggleErrorMessage();
    }
}
 
// Function to handle changes in the email field
function handleEmailChange(event) {
    const emailValue = event.target.value;
    const dobValue = document.querySelector('#dob') ? document.querySelector('#dob').value : null;
 
    if (emailValue) {
        //console.log('Email changed:', emailValue);
        sendDOBToServer(dobValue, emailValue);
    }
}
 
// Function to initialize the Place Order button event listener (optional)
function waitForPlaceOrderButton() {
    const placeOrderButton = document.querySelector('.wc-block-components-checkout-place-order-button');
    const checkoutForm = document.querySelector('.wc-block-components-form');
 
    if (placeOrderButton && checkoutForm) {
       
        placeOrderButton.addEventListener('click', toggleErrorMessage);
        //console.log('Place Order button event listener attached.');
    } else {
        console.warn('Place Order button or form not found. Retrying...');
        setTimeout(waitForPlaceOrderButton, 500);
    }
}
 
// Function to send DOB and email to the server via AJAX
function sendDOBToServer(dob, email) {
    fetch(dobData.postApiUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ dob: dob, email: email }),
    })
        .then(response => {
            if (!response.ok) {
                throw new Error('Failed to save DOB');
            }
            return response.json();  // Only parse JSON if the response is successful
        })
        .then(data => {
            if (data.status === 'success') {
                //console.log(data);
                //console.log('DOB and email saved successfully.');
            } else {
                console.error('Failed to save DOB:', data.message);
            }
        })
        .catch(error => {
            console.error('Error saving DOB:', error);
        });
}
 
// Start polling when the DOM is loaded
document.addEventListener('DOMContentLoaded', waitForBillingFieldsContainer);