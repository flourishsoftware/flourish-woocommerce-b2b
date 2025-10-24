<?php

namespace FlourishWooCommercePlugin\API;
use FlourishWooCommercePlugin\Importer\FlourishItems;
use FlourishWooCommercePlugin\Helpers\HttpRequestHelper;
use WP_REST_Request; // Import the global WP_REST_Request class.
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

class FlourishAPI 
{
    const API_LIMIT = 50;

    public $api_key;
    public $url;
    public $facility_id;
    public $auth_header;

    /**
     * Constructor - Updated for new API key authentication
     * 
     * @param string $api_key The service-based API key from Flourish
     * @param string $url The API base URL
     * @param string $facility_id The facility ID
     */
    public function __construct($api_key, $url, $facility_id)
    {
        $this->api_key = $api_key;
        $this->url = $url;
        $this->facility_id = $facility_id;
        // FIXED: Use x-api-key header instead of Bearer token
        $this->auth_header = $api_key;
    }

    /**
     * Get headers for API requests
     * 
     * @param bool $include_facility_id Whether to include FacilityID header
     * @param bool $include_content_type Whether to include Content-Type header
     * @return array Headers array
     */
    private function get_headers($include_facility_id = false, $include_content_type = false)
    {
        // FIXED: Use x-api-key header instead of Authorization: Bearer
        $headers = [
            'x-api-key: ' . $this->auth_header
        ];

        if ($include_facility_id && $this->facility_id) {
            $headers[] = 'FacilityID: ' . $this->facility_id;
        }

        if ($include_content_type) {
            $headers[] = 'Content-Type: application/json';
        }

        return $headers;
    }

    /**
     * Fetch inventory
     */
    public function fetch_product_by_id($item_id)
    {
        $api_url = $this->url . "/external/api/v1/items?item_id=$item_id";
        $headers = $this->get_headers();

        // Use the HttpRequestHelper for the API call
        try
        {
         $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
         $response_data = HttpRequestHelper::validate_response($response_http);
        } catch (\Exception $e) {
            throw new \Exception("Error fetching inventory: " . $e->getMessage());
        }
        $inventory_data = $this->fetch_inventory($item_id); 
        $data = $response_data['data'][0];
        if (isset($response_data['data']) && is_array($response_data['data'])) {
            $inventory_quantity = $inventory_data[0]['sellable_qty'];
            $response_data['data'][0]['inventory_quantity'] = $inventory_data[0]['sellable_qty'];
        } 
        $flourish_items = new FlourishItems($response_data['data']);
        $webhook_status = true;
        $imported_count = $flourish_items->save_as_woocommerce_products($this->existing_settings['item_sync_options'] ?? [],$webhook_status);
        error_log("Batch imported count: " . $imported_count);
    
    }

    /**
     * Fetches products based on optional brand filtering.
     *
     * This function retrieves products, with the option to filter results by specified brands.
     * If no filtering is applied, all available products are fetched.
     *
     * @param bool   $filter_brands Whether to filter products by brand. Defaults to false.
     * @param array  $brands        An array of brand names or IDs to filter by. Defaults to an empty array.
     * @return array An array of fetched products based on the given filters.
     */
     public function fetch_products($filter_brands = false, $brands = []) {
        $offset = 0;
        $limit = self::API_LIMIT;
        $total_imported_count = 0;
        $all_products = []; // Store all products first
    
        while (true) {
            $api_url = $this->url . "/external/api/v1/items?active=true&ecommerce_active=true&offset={$offset}&limit={$limit}";
    
            if ($filter_brands && !empty($brands)) {
                $brand_query = array_map('urlencode', $brands);
                $api_url .= "&" . implode("&", array_map(fn($brand) => "brand_name={$brand}", $brand_query));
            }
    
            $headers = $this->get_headers();
    
            try {
                $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
                $response_data = HttpRequestHelper::validate_response($response_http);
            } catch (\Exception $e) {
                error_log("Error fetching products (API call): " . $e->getMessage());
                sleep(60);
                continue;
            }
    
            if (!isset($response_data['data']) || !is_array($response_data['data'])) {
                error_log("API returned empty data.");
                break;
            }
    
            // Store products for later processing
            $all_products = array_merge($all_products, $response_data['data']);
    
            if (count($response_data['data']) < $limit) {
                break;
            }
    
            $offset += $limit;
        }
    
        // Fetch Inventory Summary in batches
        $item_ids = array_column($all_products, 'id');  
        $inventory_map = $this->fetch_bulk_inventory($item_ids); 
    
        // Assign inventory and process products in batches
        $batch = [];
        foreach ($all_products as $product) {
            $product['inventory_quantity'] = $inventory_map[$product['id']] ?? 0;
            $batch[] = $product;
    
            if (count($batch) === 50) {
                $imported_count = $this->process_batch($batch);
                $total_imported_count += $imported_count;
                $batch = [];
            }
        }
    
        if (!empty($batch)) {
            $imported_count = $this->process_batch($batch);
            $total_imported_count += $imported_count;
        }
    
        return $total_imported_count;
    }
    
    private function fetch_bulk_inventory($item_ids) {
        $inventory_map = [];
        $limit = 50; // API supports only 50 items per request
        $chunks = array_chunk($item_ids, $limit);
        
        foreach ($chunks as $batch) {
            $api_url = $this->url . "/external/api/v1/inventory/summary";
            $headers = $this->get_headers(true); // Include FacilityID header
            
            $query_params = implode('&', array_map(fn($id) => "item_id={$id}", $batch));
            $api_url = $this->url . "/external/api/v1/inventory/summary?" . $query_params;
    
            try {
                $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
                $response_data = HttpRequestHelper::validate_response($response_http);
            } catch (\Exception $e) {   
                error_log("Error fetching inventory" . $e->getMessage());
            }
    
            if (!isset($response_data['data']) || !is_array($response_data['data'])) {
                error_log("Inventory API returned empty data for batch.");
                continue; // Skip processing this batch and move to the next
            }
    
            // Merge inventory data into the main inventory map
            foreach ($response_data['data'] as $inventory) {
                $inventory_map[$inventory['item_id']] = $inventory['sellable_qty'] ?? 0;
            }
        }
    
        return $inventory_map;
    }
    
    private function process_batch($batch) {
        try {
            $flourish_items = new FlourishItems($batch);
            $webhook_status = false;
            $imported_count = $flourish_items->save_as_woocommerce_products($this->existing_settings['item_sync_options'] ?? [],$webhook_status);
            error_log("Batch imported count: " . $imported_count);
    
            unset($flourish_items);
            gc_collect_cycles();
            return $imported_count;
        } catch (\Exception $e) {
            error_log("Error importing batch: " . $e->getMessage());
            return 0;
        }
    }

    public function fetch_facilities()
    {
        $facilities = [];
        $offset = 0;
        $limit = self::API_LIMIT; 
        $has_more_facilities = true;

        while ($has_more_facilities) {
            $api_url = $this->url . "/external/api/v1/facilities?offset={$offset}&limit={$limit}";
            $headers = $this->get_headers();

            // Use the HttpRequestHelper for the API call
            try
            {
            $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
            $response_data = HttpRequestHelper::validate_response($response_http);
            } catch (\Exception $e) {
                throw new \Exception("Error fetching facility: " . $e->getMessage());
            }

            if (isset($response_data['data']) && is_array($response_data['data'])) {
                $facilities = array_merge($facilities, $response_data['data']);
            } 
            $has_more_facilities = isset($response_data['meta']['next']) && !empty($response_data['meta']['next']);

            $offset += $limit;
        }

        return $facilities;
    }

    /**
     * Fetch facility by facility_id
     */
    public function fetch_facility_config($facility_id)
		{
			$facility_config = false;
			$api_url = $this->url . "/external/api/v1/facilities/{$facility_id}";
			$headers = $this->get_headers();

			try {
				$response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
				$response_data = HttpRequestHelper::validate_response($response_http);
			} catch (\Exception $e) {
				// Handle specific HTTP codes gracefully
				if (isset($response_http['http_code'])) {
					$code = $response_http['http_code'];

					if ($code == 400 || $code == 401) {
						return [
							'error' => true,
							'message' => __('Invalid or expired Flourish API key. Please reauthenticate in plugin settings.', 'flourish-woocommerce')
						];
					}

					if ($code == 403) {
						return [
							'error' => true,
							'message' => __('Your Flourish API key is not active. Please contact Flourish support to reactivate it.', 'flourish-woocommerce')
						];
					}
				}

				// Generic fallback error
				return [
					'error' => true,
					'message' => __('Error connecting to Flourish API: ', 'flourish-woocommerce') . $e->getMessage()
				];
			}

			if (isset($response_data['data']) && is_array($response_data['data'])) {
				$facility_config = $response_data['data'];
			}

			return $facility_config;
		}


    /**
     * Fetch inventory
     */
    public function fetch_inventory($item_id)
    {
        $api_url = $this->url . "/external/api/v1/inventory/summary?item_id=$item_id";
        $headers = $this->get_headers(true); // Include FacilityID header

        // Use the HttpRequestHelper for the API call
        try
        {
         $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
         $response_data = HttpRequestHelper::validate_response($response_http);
        } catch (\Exception $e) {
            throw new \Exception("Error fetching inventory: " . $e->getMessage());
        }

        if (isset($response_data['data']) && is_array($response_data['data'])) {
            return $response_data['data'];
        } 
    }

    /**
     * Get or create a new customer using the provided customer data.
     */
    public function get_or_create_customer_by_email($customer)
    {
        $api_url = $this->url . "/external/api/v1/customers?email=" . urlencode($customer['email']);
        $headers = $this->get_headers();

        try
        {
         $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
         $response_data = HttpRequestHelper::validate_response($response_http);
        } catch (\Exception $e) {
            throw new \Exception("Error fetching customer: " . $e->getMessage());
        }

        if (isset($response_data['data']) && is_array($response_data['data'])) {
            if (count($response_data['data'])) {
                $customer['flourish_customer_id'] = $response_data['data'][0]['id'];
            } else {
                // No customer found, create a new one
                return $this->create_customer($customer);
            }
        } else {
            throw new \Exception('Invalid API response format.');
        }

        return $customer;
    }

    /**
     * Create a new customer using the provided customer data.
     */
    private function create_customer($customer)
    {
        // Check Date of Birth
        if (empty($customer['dob'])) {
            wc_add_notice(__('Date of Birth is required. Please update your account details.', 'woocommerce'), 'error');
            throw new \Exception('Date of Birth is required. Please update your account details.');
        }

        // Prepare the URL and headers for the POST request
        $api_url = $this->url . "/external/api/v1/customers";
        $headers = $this->get_headers(false, true); // Include Content-Type header

        try {
            // Use HttpRequestHelper to create a customer
            $response_http = HttpRequestHelper::make_request($api_url, 'POST', $headers, json_encode($customer));
            $response_data = HttpRequestHelper::validate_response($response_http);
        } catch (\Exception $e) {
            throw new \Exception("Error creating customer: " . $e->getMessage());
        }

        if (isset($response_data['data']) && is_array($response_data['data'])) {
            $customer['flourish_customer_id'] = $response_data['data']['id'];
        } 

        return $customer;
    }

    public function create_retail_order($order) {
        $api_url = $this->url . "/external/api/v2/retail-orders";
        $headers = $this->get_headers(true, true); // Include both FacilityID and Content-Type

        try {
            // Use HttpRequestHelper to create a retail order
            $response_http = HttpRequestHelper::make_request($api_url, 'POST', $headers, json_encode($order));
            $response_data = HttpRequestHelper::validate_response($response_http);
        } catch (\Exception $e) {
            throw new \Exception("Error creating retail order: " . $e->getMessage());
        }

        if (isset($response_data['data']) && is_array($response_data['data'])) {
            $order['flourish_order_id'] = $response_data['data']['id'];
        } 
        return $response_data['data']['id'];
    }

    public function create_outbound_order($order) {
        $api_url = $this->url . "/external/api/v1/outbound-orders";
        $headers = $this->get_headers(true, true); // Include both FacilityID and Content-Type

        try {
            // Use HttpRequestHelper to create a outbound order
            $response_http = HttpRequestHelper::make_request($api_url, 'POST', $headers, json_encode($order));
            $response_data = HttpRequestHelper::validate_response($response_http);
        } catch (\Exception $e) {
            throw new \Exception("Error creating outbound order: " . $e->getMessage());
        }

        if (isset($response_data['data']) && is_array($response_data['data'])) {
            $order['flourish_order_id'] = $response_data['data']['id'];
        } 

        return $response_data['data']['id'];
    }

    public function fetch_brands()
    {
        $brands = [];
        $offset = 0;
        $limit = self::API_LIMIT; 
        $has_more_brands = true;

        while ($has_more_brands) {
            $api_url = $this->url . "/external/api/v1/brands?offset={$offset}&limit={$limit}";
            $headers = $this->get_headers();

            // Use the HttpRequestHelper for the API call
            try
            {
             $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
             $response_data = HttpRequestHelper::validate_response($response_http);
            } catch (\Exception $e) {
                throw new \Exception("Error fetching brands: " . $e->getMessage());
            }

            if (isset($response_data['data']) && is_array($response_data['data'])) {
                $brands = array_merge($brands, $response_data['data']);
            } 
            $has_more_brands = isset($response_data['meta']['next']) && !empty($response_data['meta']['next']);

            $offset += $limit;
        }

        return $brands;
    }

    public function fetch_sales_reps()
    {
        $sales_reps = [];
        $offset = 0;
        $limit = self::API_LIMIT; 
        $has_more_sales_reps = true;

        while ($has_more_sales_reps) {
            $api_url = $this->url . "/external/api/v1/sales-reps?offset={$offset}&limit={$limit}";
            $headers = $this->get_headers(true); // Include FacilityID header

            // Use the HttpRequestHelper for the API call
            try
            {
             $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
             $response_data = HttpRequestHelper::validate_response($response_http);
            } catch (\Exception $e) {
                throw new \Exception("Error fetching sales rep: " . $e->getMessage());
            }
             
            if (isset($response_data['data']) && is_array($response_data['data'])) {
                $sales_reps = array_merge($sales_reps, $response_data['data']);
            } 

            $has_more_sales_reps = isset($response_data['meta']['next']) && !empty($response_data['meta']['next']);

            $offset += $limit;
        }

        return $sales_reps;
    }

    public function fetch_destination_by_license($license)
    {
       $license_value = $license;
        $api_url = $this->url . "/external/api/v1/destinations?license_number=" . urlencode($license_value);
        $headers = $this->get_headers();

        // Use the HttpRequestHelper for the API call
        try
        {
         $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
         $response_data = HttpRequestHelper::validate_response($response_http);
        } catch (\Exception $e) {
            throw new \Exception("Error fetching destination by license: " . $e->getMessage());
        }

        if (isset($response_data['data']) && is_array($response_data['data'])) {
            if (count($response_data['data'])) {
                return $response_data['data'][0];
            }
        } 
        return false;
    }

    public function fetch_destination_by_destination_id($destination_id)
    {
        $destination_value = $destination_id;
        $api_url = $this->url . "/external/api/v1/destinations/" . urlencode($destination_value);
        $headers = $this->get_headers();

        // Use the HttpRequestHelper for the API call
        try
        {
         $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
         $response_data = HttpRequestHelper::validate_response($response_http);
        } catch (\Exception $e) {
            throw new \Exception("Error fetching destination by destination_id: " . $e->getMessage());
        }

        if (isset($response_data['data']) && is_array($response_data['data']) && count($response_data['data'])) {
        // Return all destinations instead of just the first one
        return $response_data['data'];
        }
        return false;
    }

     public function fetch_destination_by_facility_name()
{
    $all_destinations = [];
    $offset = 0;
    $limit = self::API_LIMIT;
    
    do {
        // Fix the API URL construction - there was a syntax error in your original code
        $api_url = $this->url . "/external/api/v1/destinations?offset=" . $offset . "&limit=" . $limit;
        $headers = $this->get_headers();
        
        // Use the HttpRequestHelper for the API call
        try {
            $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
            $response_data = HttpRequestHelper::validate_response($response_http);
        } catch (\Exception $e) {
            throw new \Exception("Error fetching destination by facility_name: " . $e->getMessage());
        }
        
        // Check if we got data
        if (isset($response_data['data']) && is_array($response_data['data']) && count($response_data['data'])) {
            // Merge current batch with all destinations
            $all_destinations = array_merge($all_destinations, $response_data['data']);
            
            // If we got less than the limit, we've reached the end
            $has_more_data = count($response_data['data']) == $limit;
            
            // Increment offset for next batch
            $offset += $limit;
        } else {
            // No more data
            $has_more_data = false;
        }
        
    } while ($has_more_data);
    
    // Return all destinations or false if none found
    return count($all_destinations) > 0 ? $all_destinations : false;
}

    public function fetch_uoms()
    {
        $uoms = [];
        $offset = 0;
        $limit = self::API_LIMIT; 
        $has_more_uoms = true;

        while ($has_more_uoms) {
            $api_url = $this->url . "/external/api/v1/uoms?offset={$offset}&limit={$limit}";
            $headers = $this->get_headers();

            // Use the HttpRequestHelper for the API call
            try
            {
             $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
             $response_data = HttpRequestHelper::validate_response($response_http);
            } catch (\Exception $e) {
                // Check if it's an authorization error
                if (str_contains($e->getMessage(), '401')) {
                    return 'Authorization denied. Please check your API key.';
                }

                // General error message
                return 'An error occurred while fetching UOMs. Please try again later.';
            }

            if (isset($response_data['data']) && is_array($response_data['data'])) {
                $uoms = array_merge($uoms, $response_data['data']);
            } 
            $has_more_uoms = isset($response_data['meta']['next']) && !empty($response_data['meta']['next']);

            $offset += $limit;
        }

        return $uoms;
   }
 
    
   // Helper method to get destination options
public function get_destination_options() 
{
    // Initialize API
    $destinations = $this->fetch_destination_by_facility_name();
    $destination_options = [];
    
    if ($destinations && is_array($destinations)) {
        // Prepare array for sorting
        $temp_array = [];
        foreach ($destinations as $destination) {
            $id = $destination['id'];
            // Use name instead of alias since alias is empty
            // Check if alias is empty, use name as fallback
            $name = !empty($destination['alias']) ? $destination['alias'] : $destination['name'];
            $license_number = !empty($destination['license_number']) ? $destination['license_number'] : 'No License';
            // Format: "Name - License"
            $display_text = $name . ' (' . $license_number . ')';
            $temp_array[] = [
                'id' => $id,
                'display_text' => $display_text,
                'sort_key' => $name // Use name for sorting
            ];
        }
        
        // Sort the array by name (alphanumerically)
        usort($temp_array, function($a, $b) {
            return strnatcasecmp($a['sort_key'], $b['sort_key']);
        });
        
        // Build the final associative array
        foreach ($temp_array as $item) {
            $destination_options[$item['id']] = $item['display_text'];
        }
    }
    
    return $destination_options;
}

   /**
     * Get the order by id.
     */
    public function get_order_by_id($order_id,$order_type_api)
    {
        $api_url = $this->url . "/external/api/v1/{$order_type_api}/{$order_id}";
        $headers = $this->get_headers();

        try
        {
         $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
         $response_data = HttpRequestHelper::validate_response($response_http);
        } catch (\Exception $e) {
            throw new \Exception("Error fetching get order by id: " . $e->getMessage());
        }

        if (isset($response_data['data']) && is_array($response_data['data'])) {
            $order_data = $response_data['data'];
        } else {
            throw new \Exception('Invalid API response format.');
        }

        return $order_data;
    }

    /**
     * Update a existing outbound order
     */
    public function update_outbound_order($order,$flourish_order_id) {
        $api_url = $this->url . "/external/api/v1/outbound-orders/{$flourish_order_id}";
        $headers = $this->get_headers(true, true); // Include both FacilityID and Content-Type

        try {
            // Use HttpRequestHelper to update outbound order
            $response_http = HttpRequestHelper::make_request($api_url, 'PUT', $headers, json_encode($order));
            $response_data = HttpRequestHelper::validate_response($response_http);
        } catch (\Exception $e) {
            throw new \Exception("Error updating outbound order: " . $e->getMessage());
        }

        if (isset($response_data['data']) && is_array($response_data['data'])) {
            $order['flourish_order_id'] = $response_data['data']['id'];
        } 

        return $response_data['data']['id'];
    }

    public function update_retail_order($order,$flourish_order_id) {
        $api_url = $this->url . "/external/api/v1/retail-orders/{$flourish_order_id}";
        $headers = $this->get_headers(true, true); // Include both FacilityID and Content-Type

        try {
            // Use HttpRequestHelper to update retail order
            $response_http = HttpRequestHelper::make_request($api_url, 'PUT', $headers, json_encode($order));
            $response_data = HttpRequestHelper::validate_response($response_http);
        } catch (\Exception $e) {
            throw new \Exception("Error updating retail order: " . $e->getMessage());
        }

        if (isset($response_data['data']) && is_array($response_data['data'])) {
            $order['flourish_order_id'] = $response_data['data']['id'];
        } 
        return $response_data['data']['id'];
    }
}