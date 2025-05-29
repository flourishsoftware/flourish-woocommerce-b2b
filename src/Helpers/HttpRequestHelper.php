<?php

namespace FlourishWooCommercePlugin\Helpers;

class HttpRequestHelper
{   
    /**
     * Make an HTTP request using cURL.
     *
     * @param string $url The endpoint URL.
     * @param string $method HTTP method (GET, POST, PUT).
     * @param array $headers Request headers.
     * @param mixed $body Request body for POST/PUT.
     * @return array Response data and HTTP status code.
     * @throws \Exception If cURL fails.
     */
    public static function make_request($url, $method = 'GET', $headers = [], $body = null)
    {
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if ($method === 'POST' || $method === 'PUT') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);

        if ($response === false) {
            $error_message = curl_error($ch);
            curl_close($ch);
            throw new \Exception("cURL Error: $error_message");
        }

        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'response' => $response,
            'http_code' => $http_code,
        ];
    }
    /**
     * Validate API response.
     *
     * @param array $response_http Response data and HTTP code.
     * @param int $expected_code Expected HTTP status code.
     * @return array Decoded response data.
     * @throws \Exception If response is invalid.
     */
    public static function validate_response($response_http, $expected_code = 200)
    {
        $http_code = $response_http['http_code'];
        $response = $response_http['response'];

        if ($http_code != $expected_code) {
            throw new \Exception("Did not get $expected_code response from API. Got: $http_code. Response: $response.");
        }

        $response_data = json_decode($response, true);

        if ($response_data === null || !isset($response_data['data']) || !is_array($response_data['data'])) {
            throw new \Exception('Invalid API response format.');
        }

        return $response_data;
    }
    /**
     * Sends an email to the admin when an order fails.
     *
     * @param string $error_message The error message to include in the email.
     */
    public static function send_order_failure_email_to_admin($error_message,$order_id) {
        // Define admin email
        $admin_email = get_option('admin_email');

        // Email subject and message
        $subject = "Action Required: Order #{$order_id} Stuck and Not Delivered";

        $message = <<<EOD
        Subject: Action Required: Order #{$order_id} Stuck and Not Delivered

        Dear Admin,

        We noticed that Order #{$order_id} has encountered an issue and did not reach its destination. To resolve this, please investigate and take the necessary action.

        Error Details: {$error_message}

        Thank you!
        --
        Regards,
        Flourish Team
        EOD;

        // Headers for the email
        $headers = ['Content-Type: text/plain; charset=UTF-8'];

        // Send email and log the result
        if (wp_mail($admin_email, $subject, $message, $headers)) {
            error_log('Order failure email sent successfully.');
        } else {
            error_log('Failed to send order failure email.');
        }
    }
}
