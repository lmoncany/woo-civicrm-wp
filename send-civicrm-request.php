<?php
if (!defined('WPINC')) {
    die;
}

trait WC_CiviCRM_API_Request
{
    /**
     * Test connection to CiviCRM API
     * 
     * This function performs a simple API call to test if the CiviCRM connection is working.
     * Returns an array with status, message, and any additional debug information.
     * 
     * @return array Connection status and details
     */
    public function test_civicrm_connection()
    {
        try {
            // Make sure we have the required credentials
            if (empty($this->civicrm_url)) {
                return [
                    'success' => false,
                    'status' => 'disconnected',
                    'message' => 'CiviCRM URL is not configured'
                ];
            }

            if (empty($this->auth_token)) {
                return [
                    'success' => false,
                    'status' => 'disconnected',
                    'message' => 'CiviCRM API Token is not configured'
                ];
            }

            // Create a simple endpoint for the Contact.get API call
            $endpoint = rtrim($this->civicrm_url, '/') . '/civicrm/ajax/api4/Contact/get';

            // Set up headers
            $headers = [
                'Content-Type: application/x-www-form-urlencoded',
                'X-Civi-Auth: Bearer ' . $this->auth_token,
                'X-Requested-With: XMLHttpRequest',
                'Accept: application/json'
            ];

            // Create minimal request params
            $request_data = [
                'params' => json_encode([
                    'select' => ['id'],
                    'limit' => 1,
                    'checkPermissions' => false
                ])
            ];

            // Set context for the API request
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => $headers,
                    'content' => http_build_query($request_data),
                    'timeout' => 15 // Shorter timeout for connection test
                ]
            ]);

            // Try to make the request
            $response_raw = @file_get_contents($endpoint, false, $context);

            // If request failed
            if ($response_raw === false) {
                $error = error_get_last();
                return [
                    'success' => false,
                    'status' => 'disconnected',
                    'message' => 'Could not connect to CiviCRM API: ' . ($error['message'] ?? 'Unknown error'),
                    'http_error' => $http_response_header ?? []
                ];
            }

            // Parse the response
            $response = json_decode($response_raw, true);

            // Check if valid response format
            if (!is_array($response) || !isset($response['values'])) {
                return [
                    'success' => false,
                    'status' => 'disconnected',
                    'message' => 'Invalid response from CiviCRM API',
                    'response' => $response_raw
                ];
            }

            // Connection successful
            return [
                'success' => true,
                'status' => 'connected',
                'message' => 'Successfully connected to CiviCRM API',
                'data' => [
                    'contactCount' => count($response['values']),
                    'civicrm_version' => $response['civi_api_version'] ?? 'Unknown'
                ]
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'status' => 'disconnected',
                'message' => 'Error connecting to CiviCRM: ' . $e->getMessage(),
                'exception' => [
                    'code' => $e->getCode(),
                    'trace' => $e->getTraceAsString()
                ]
            ];
        }
    }

    private function log_api_request($entity, $action, $params)
    {
        if (class_exists('WC_CiviCRM_Logger')) {
            WC_CiviCRM_Logger::log_success('api_request', [
                'message' => 'Sending CiviCRM API request',
                'entity' => $entity,
                'action' => $action,
                'params' => $params
            ]);
        }
    }

    private function log_api_response($endpoint, $status, $result)
    {
        if (class_exists('WC_CiviCRM_Logger')) {
            WC_CiviCRM_Logger::log_success('api_response', [
                'message' => 'Received API response',
                'endpoint' => $endpoint,
                'status' => $status,
                'response' => $result,
                'headers' => $http_response_header ?? []
            ]);
        }
    }

    private function log_api_error($endpoint, $entity, $action, $error)
    {
        if (class_exists('WC_CiviCRM_Logger')) {
            WC_CiviCRM_Logger::log_error('api_error', [
                'message' => 'API request failed',
                'endpoint' => $endpoint,
                'entity' => $entity,
                'action' => $action,
                'error' => $error instanceof Exception ? $error->getMessage() : $error,
                'trace' => $error instanceof Exception ? $error->getTraceAsString() : null
            ]);
        }
    }

    public function send_civicrm_request($entity, $action, $params = [])
    {
        // Validate required parameters
        if (empty($entity) || empty($action)) {
            throw new Exception('Entity and action are required');
        }

        // Prepare the endpoint
        $endpoint = $params['_endpoint'] ?? $this->get_api_endpoint($entity, $action);

        // Prepare request parameters
        // For 'get' actions, pass parameters directly
        if ($action === 'get') {
            $request_data = [
                'params' => json_encode($params)
            ];
        } else {
            // For other actions, use values key
            $request_data = [
                'params' => json_encode([
                    'values' => $params['values'] ?? [],
                    'checkPermissions' => $params['checkPermissions'] ?? false
                ])
            ];

            if (isset($request_data['params'])) {
                $params_array = json_decode($request_data['params'], true);
                $params_array['values'] = array_filter($params_array['values'], function ($key) {
                    return !str_contains($key, ':');
                }, ARRAY_FILTER_USE_KEY);
                $request_data['params'] = json_encode($params_array);
            }
        }


        // Remove any additional metadata keys that might interfere with the request
        

        // Prepare headers
        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'X-Civi-Auth: Bearer ' . $this->auth_token,
            'X-Requested-With: XMLHttpRequest',
            'Accept: application/json'
        ];

        // Prepare request context
        $request_context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headers,
                'content' => http_build_query($request_data)
            ]
        ]);

        // Log request details for debugging
        error_log('CiviCRM API Request Details:');
        error_log('Endpoint: ' . $endpoint);
        error_log('Entity: ' . $entity);
        error_log('Action: ' . $action);
        error_log('Params: ' . json_encode($request_data, JSON_PRETTY_PRINT));
        error_log('Auth Token: ' . substr($this->auth_token, 0, 5) . '...' . substr($this->auth_token, -5));

        // Execute the request
        try {
            $response_raw = @file_get_contents($endpoint, false, $request_context);

            if ($response_raw === false) {
                throw new Exception('Failed to connect to CiviCRM API');
            }

            $response = json_decode($response_raw, true);

            // Log HTTP status and raw response
            error_log('HTTP Status: ' . (isset($http_response_header[0]) ? $http_response_header[0] : 'Unknown'));
            error_log('Raw Response: ' . $response_raw);

            // Check for API errors
            if (isset($response['status']) && $response['status'] == 500) {
                throw new Exception($response['error_message'] ?? 'Unknown API error');
            }

            return $response;
        } catch (Exception $e) {
            // Log any exceptions
            error_log('CiviCRM API Request Error: ' . $e->getMessage());
            throw $e;
        }
    }
}
