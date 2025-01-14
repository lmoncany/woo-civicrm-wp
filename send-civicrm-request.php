<?php
if (!defined('WPINC')) {
    die;
}

trait WC_CiviCRM_API_Request {
    private function log_api_request($entity, $action, $params) {
        if (class_exists('WC_CiviCRM_Logger')) {
            WC_CiviCRM_Logger::log_success('api_request', [
                'message' => 'Sending CiviCRM API request',
                'entity' => $entity,
                'action' => $action,
                'params' => $params
            ]);
        }
    }

    private function log_api_response($endpoint, $status, $result) {
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

    private function log_api_error($endpoint, $entity, $action, $error) {
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

    private function send_civicrm_request($entity, $action, $params) {
        try {
            // Use exact API4 endpoint
            $endpoint = 'https://testwpcivi.appli.in/civicrm/ajax/api4/' . $entity . '/' . $action;

            // Format request for API4
            $request_data = ['checkPermissions' => false];

            if ($action === 'getFields') {
                // For getFields action, we don't need select/where
                $request_data = array_merge($request_data, [
                    'loadOptions' => true,
                    'includeCustom' => true
                ], $params);
            } else if ($action === 'get') {
                $request_data = array_merge($request_data, [
                    'select' => isset($params['select']) ? $params['select'] : ['*'],
                    'where' => isset($params['where']) ? $params['where'] : []
                ]);
            } else if ($action === 'create' || $action === 'update') {
                $request_data = array_merge($request_data, [
                    'values' => $params['values'] ?? []
                ]);
            }

            // Log API request
            $this->log_api_request($entity, $action, $request_data);

            // Create stream context with API4 headers
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => [
                        'Content-Type: application/json',
                        'X-Civi-Auth: Bearer ' . $this->auth_token,
                        'Accept: application/json'
                    ],
                    'content' => json_encode($request_data)
                ]
            ]);

            // Make the API request
            $response = file_get_contents($endpoint, false, $context);
            if ($response === false) {
                throw new Exception('Failed to connect to CiviCRM API');
            }

            $result = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON response: ' . json_last_error_msg());
            }

            // Get response headers to check status
            $status = 200; // Default to 200 if headers not available
            if (isset($http_response_header)) {
                foreach ($http_response_header as $header) {
                    if (preg_match('/^HTTP\/\d\.\d\s+(\d+)/', $header, $matches)) {
                        $status = intval($matches[1]);
                        break;
                    }
                }
            }

            // Log API response
            $this->log_api_response($endpoint, $status, $result);

            if (!empty($result['error'])) {
                throw new Exception($result['error']['message'] ?? 'Unknown API error');
            }

            return $result;
        } catch (Exception $e) {
            // Log API error
            $this->log_api_error($endpoint ?? '', $entity, $action, $e);
            throw $e;
        }
    }
}
