<?php
/**
 * AWS SDK Integration for FeatherLift Media Plugin
 * This class handles all AWS operations including S3, SQS, and CloudFront
 */

class Enhanced_S3_AWS_SDK {
    private $access_key;
    private $secret_key;
    private $region;
    
    public function __construct($access_key, $secret_key, $region = 'us-east-1') {
        $this->access_key = $access_key;
        $this->secret_key = $secret_key;
        $this->region = $region;
    }
    
    /**
     * Create S3 bucket with proper configuration
     */
    public function create_s3_bucket($bucket_name, $args = array()) {
        try {
            $defaults = array(
                'preserve_permissions' => false
            );
            $args = array_merge($defaults, $args);
            // Extract the parent folder and actual bucket name
            $path_parts = explode('/', $bucket_name);
            $actual_bucket = array_pop($path_parts);
            $parent_folder = implode('/', $path_parts);
            
            // Create bucket
            $result = $this->make_s3_request('PUT', $actual_bucket, '');
            
            if (!$result['success']) {
                return $result;
            }
            
            if (empty($args['preserve_permissions'])) {
                // Set bucket policy for public read access
                $policy = json_encode(array(
                    "Version" => "2012-10-17",
                    "Statement" => array(
                        array(
                            "Sid" => "PublicReadGetObject",
                            "Effect" => "Allow",
                            "Principal" => "*",
                            "Action" => "s3:GetObject",
                            "Resource" => "arn:aws:s3:::{$bucket_name}/*"
                        )
                    )
                ));
                
                // Apply the policy
                $this->make_s3_request('PUT', $bucket_name, '', array('policy' => ''), $policy, 'application/json');
                
                // Configure bucket for static website hosting
                $website_config = '<?xml version="1.0" encoding="UTF-8"?>
                    <WebsiteConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/">
                        <IndexDocument>
                            <Suffix>index.html</Suffix>
                        </IndexDocument>
                        <ErrorDocument>
                            <Key>error.html</Key>
                        </ErrorDocument>
                    </WebsiteConfiguration>';
                
                $this->make_s3_request('PUT', $actual_bucket, '', array('website' => ''), $website_config);
                
                // Set CORS configuration
                $cors_config = '<?xml version="1.0" encoding="UTF-8"?>
                    <CORSConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/">
                        <CORSRule>
                            <AllowedOrigin>*</AllowedOrigin>
                            <AllowedMethod>GET</AllowedMethod>
                            <AllowedMethod>HEAD</AllowedMethod>
                            <AllowedHeader>*</AllowedHeader>
                            <MaxAgeSeconds>3000</MaxAgeSeconds>
                        </CORSRule>
                    </CORSConfiguration>';
                
                $this->make_s3_request('PUT', $actual_bucket, '', array('cors' => ''), $cors_config);
            }
            
            return array(
                'success' => true,
                'bucket_name' => $actual_bucket,
                'full_path' => $bucket_name
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }
    
    /**
     * Create SQS queue
     */
    public function create_sqs_queue($queue_name) {
        try {
            $queue_url = $this->make_sqs_request('CreateQueue', array(
                'QueueName' => $queue_name,
                'Attribute.1.Name' => 'VisibilityTimeout',
                'Attribute.1.Value' => '300',
                'Attribute.2.Name' => 'MessageRetentionPeriod',
                'Attribute.2.Value' => '1209600',
                'Attribute.3.Name' => 'ReceiveMessageWaitTimeSeconds',
                'Attribute.3.Value' => '20'
            ));
            
            return array(
                'success' => true,
                'queue_url' => $queue_url,
                'queue_name' => $queue_name
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }
    
    /**
     * Create CloudFront distribution
     */
    public function create_cloudfront_distribution($bucket_name) {
        try {
            
            
            $origin_domain = $bucket_name . '.s3.' . $this->region . '.amazonaws.com';
error_log("Creating CloudFront for origin: " . $origin_domain);
            
            $distribution_config = array(
                'CallerReference' => uniqid('wp-s3-', true),
                'Comment' => 'WordPress S3 Distribution',
                'Enabled' => true,
                'Origins' => array(
                    'Quantity' => 1,
                    'Items' => array(
                        array(
                            'Id' => 'S3-' . $bucket_name,
                            'DomainName' => $origin_domain,
                            'S3OriginConfig' => array(
                                'OriginAccessIdentity' => ''
                            )
                        )
                    )
                ),
                'DefaultCacheBehavior' => array(
                    'TargetOriginId' => 'S3-' . $bucket_name,
                    'ViewerProtocolPolicy' => 'redirect-to-https',
                    'TrustedSigners' => array(
                        'Enabled' => false,
                        'Quantity' => 0
                    ),
                    'ForwardedValues' => array(
                        'QueryString' => false,
                        'Cookies' => array('Forward' => 'none')
                    )
                )
            );
            
            error_log("CloudFront config: " . print_r($distribution_config, true));
            
            $result = $this->make_cloudfront_request('POST', 'distribution', $distribution_config);
            
            error_log("CloudFront API response: " . print_r($result, true));
            
            // Check if distribution was actually created
            if (isset($result['Distribution']['Id'])) {
                return array(
                    'success' => true,
                    'distribution_id' => $result['Distribution']['Id'],
                    'domain' => $result['Distribution']['DomainName'],
                    'status' => $result['Distribution']['Status']
                );
            } else {
                throw new Exception('CloudFront distribution not created - invalid response');
            }
            
        } catch (Exception $e) {
            error_log("CloudFront creation error: " . $e->getMessage());
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }
    
    /**
     * Upload file to S3
     */
    public function upload_file_to_s3($file_path, $bucket_name, $s3_key, $content_type = 'application/octet-stream') {
        try {
            if (!file_exists($file_path)) {
                throw new Exception('File does not exist: ' . $file_path);
            }
            
            $file_content = file_get_contents($file_path);
            if ($file_content === false) {
                throw new Exception('Could not read file: ' . $file_path);
            }
            
            $result = $this->make_s3_request('PUT', $bucket_name, $s3_key, array(), $file_content, $content_type);
            
            if ($result['success']) {
                return array(
                    'success' => true,
                    'url' => "https://{$bucket_name}.s3.{$this->region}.amazonaws.com/{$s3_key}",
                    's3_key' => $s3_key,
                    'file_size' => strlen($file_content)
                );
            }
            
            return $result;
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }
    
    /**
     * Download file from S3
     */
    public function download_file_from_s3($bucket_name, $s3_key, $local_path) {
        try {
            $result = $this->make_s3_request('GET', $bucket_name, $s3_key);
            
            if (!$result['success']) {
                return $result;
            }
            
            // Ensure directory exists
            $dir = dirname($local_path);
            if (!is_dir($dir)) {
                wp_mkdir_p($dir);
            }
            
            $bytes_written = file_put_contents($local_path, $result['body']);
            
            if ($bytes_written === false) {
                throw new Exception('Could not write file: ' . $local_path);
            }
            
            return array(
                'success' => true,
                'local_path' => $local_path,
                'file_size' => $bytes_written
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }
    
    /**
     * Delete file from S3
     */
    public function delete_file_from_s3($bucket_name, $s3_key) {
        try {
            $result = $this->make_s3_request('DELETE', $bucket_name, $s3_key);
            return $result;
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }

    public function list_s3_objects($bucket_name, $continuation_token = null) {
        try {
            $params = array('list-type' => 2);
            if (!empty($continuation_token)) {
                $params['continuation-token'] = $continuation_token;
            }

            $result = $this->make_s3_request('GET', $bucket_name, '', $params);
            if (!$result['success']) {
                return $result;
            }

            $xml = simplexml_load_string($result['body']);
            $objects = array();
            if ($xml && isset($xml->Contents)) {
                foreach ($xml->Contents as $object) {
                    $objects[] = array('Key' => (string) $object->Key);
                }
            }

            $next = ($xml && isset($xml->NextContinuationToken)) ? (string) $xml->NextContinuationToken : null;

            return array(
                'success' => true,
                'objects' => $objects,
                'next_token' => $next
            );
        } catch (Exception $e) {
            return array('success' => false, 'error' => $e->getMessage());
        }
    }

    public function delete_s3_bucket($bucket_name) {
        try {
            $result = $this->make_s3_request('DELETE', $bucket_name);
            return $result;
        } catch (Exception $e) {
            return array('success' => false, 'error' => $e->getMessage());
        }
    }

    public function delete_sqs_queue($queue_url) {
        try {
            $this->make_sqs_request('DeleteQueue', array('QueueUrl' => $queue_url));
            return array('success' => true);
        } catch (Exception $e) {
            return array('success' => false, 'error' => $e->getMessage());
        }
    }

    public function delete_cloudfront_distribution($distribution_id) {
        try {
            // Fetch config + ETag
            $config_result = $this->make_cloudfront_request('GET', 'distribution/' . $distribution_id . '/config');
            $headers = isset($config_result['headers']) ? $config_result['headers'] : array();
            $etag = '';
            if (is_array($headers)) {
                $etag = $headers['etag'] ?? $headers['ETag'] ?? '';
            } elseif (is_object($headers)) {
                $etag = $headers->offsetExists('etag') ? $headers->offsetGet('etag') : ($headers->offsetExists('ETag') ? $headers->offsetGet('ETag') : '');
            }

            if (empty($etag)) {
                throw new Exception('Unable to determine CloudFront ETag.');
            }

            $config_xml = $config_result['raw_body'] ?? '';
            if ($config_xml && strpos($config_xml, '<Enabled>true</Enabled>') !== false) {
                $disabled_xml = str_replace('<Enabled>true</Enabled>', '<Enabled>false</Enabled>', $config_xml);
                $this->make_cloudfront_request('PUT', 'distribution/' . $distribution_id . '/config', $disabled_xml, array('If-Match' => $etag));
                return array(
                    'success' => false,
                    'error' => 'Distribution disabled. AWS requires propagation before deletion; try again in a few minutes.'
                );
            }

            $this->make_cloudfront_request('DELETE', 'distribution/' . $distribution_id, null, array('If-Match' => $etag));
            return array('success' => true);
        } catch (Exception $e) {
            return array('success' => false, 'error' => $e->getMessage());
        }
    }
    
    /**
     * Send message to SQS queue
     */
    public function send_sqs_message($queue_url, $message) {
        try {
            $result = $this->make_sqs_request('SendMessage', array(
                'QueueUrl' => $queue_url,
                'MessageBody' => json_encode($message),
                'MessageAttribute.1.Name' => 'Operation',
                'MessageAttribute.1.Value.StringValue' => $message['operation'],
                'MessageAttribute.1.Value.DataType' => 'String'
            ));
            
            return array(
                'success' => true,
                'message_id' => isset($result['SendMessageResult']['MessageId']) ? 
                    $result['SendMessageResult']['MessageId'] : 
                    (isset($result['MessageId']) ? $result['MessageId'] : 'unknown')
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }
    
    /**
     * Receive messages from SQS queue
     */
    public function receive_sqs_messages($queue_url, $max_messages = 10) {
        try {
            $max_messages = min($max_messages, 10);
            $result = $this->make_sqs_request('ReceiveMessage', array(
                'QueueUrl' => $queue_url,
                'AttributeName.1' => 'All',
                'MaxNumberOfMessages' => $max_messages,
                'WaitTimeSeconds' => 1  // Short polling for testing
            ));
            
            // Extract messages from result
            $messages = array();
            if (isset($result['ReceiveMessageResult']['Message'])) {
                $message_data = $result['ReceiveMessageResult']['Message'];
                // Handle single message vs array of messages
                if (isset($message_data['MessageId'])) {
                    $messages[] = $message_data;
                } else {
                    $messages = $message_data;
                }
            }
            
            return array(
                'success' => true,
                'messages' => $messages
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }
    
    /**
     * Delete message from SQS queue
     */
    public function delete_sqs_message($queue_url, $receipt_handle) {
        try {
            $this->make_sqs_request('DeleteMessage', array(
                'QueueUrl' => $queue_url,
                'ReceiptHandle' => $receipt_handle
            ));
            
            return array('success' => true);
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }
    
    /**
     * Make S3 API request
     */
    private function make_s3_request($method, $bucket, $key = '', $query_params = array(), $body = '', $content_type = 'application/octet-stream') {
        $host = $bucket . '.s3.' . $this->region . '.amazonaws.com';
        $endpoint = 'https://' . $host . '/' . $key;
        
        if (!empty($query_params)) {
            $endpoint .= '?' . http_build_query($query_params);
        }
        
        $datetime = gmdate('Ymd\THis\Z');
        $date = substr($datetime, 0, 8);
        
        // Create canonical request
        $content_sha256 = hash('sha256', $body);
        $content_md5 = base64_encode(hex2bin(md5($body)));
        
        $canonical_headers = "host:" . $host . "\n";
        $canonical_headers .= "x-amz-content-sha256:" . $content_sha256 . "\n";
        $canonical_headers .= "x-amz-date:" . $datetime . "\n";
        
        if ($method === 'PUT' && !empty($body)) {
            $canonical_headers = "content-md5:" . $content_md5 . "\n" . 
                                "content-type:" . $content_type . "\n" . 
                                $canonical_headers;
            $signed_headers = "content-md5;content-type;host;x-amz-content-sha256;x-amz-date";
        } else {
            $signed_headers = "host;x-amz-content-sha256;x-amz-date";
        }
        
        $canonical_request = $method . "\n";
        $canonical_request .= "/" . $key . "\n";
        $canonical_request .= http_build_query($query_params) . "\n";
        $canonical_request .= $canonical_headers . "\n";
        $canonical_request .= $signed_headers . "\n";
        $canonical_request .= $content_sha256;
        
        // Create string to sign
        $credential_scope = $date . "/" . $this->region . "/s3/aws4_request";
        $string_to_sign = "AWS4-HMAC-SHA256\n";
        $string_to_sign .= $datetime . "\n";
        $string_to_sign .= $credential_scope . "\n";
        $string_to_sign .= hash('sha256', $canonical_request);
        
        // Calculate signature
        $signature = $this->generate_signature_v4($date, $string_to_sign, 's3');
        
        // Create authorization header
        $authorization = "AWS4-HMAC-SHA256 Credential=" . $this->access_key . "/" . $credential_scope;
        $authorization .= ", SignedHeaders=" . $signed_headers;
        $authorization .= ", Signature=" . $signature;
        
        // Make HTTP request
        $headers = array(
            'Host' => $host,
            'X-Amz-Content-SHA256' => $content_sha256,
            'X-Amz-Date' => $datetime,
            'Authorization' => $authorization
        );
        
        if ($method === 'PUT' && !empty($body)) {
            $headers['Content-MD5'] = $content_md5;
            $headers['Content-Type'] = $content_type;
        }
        
        $args = array(
            'method' => $method,
            'timeout' => 60,
            'headers' => $headers,
            'body' => $body
        );
        
        $response = wp_remote_request($endpoint, $args);
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message()
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($status_code >= 200 && $status_code < 300) {
            return array(
                'success' => true,
                'body' => $response_body,
                'status_code' => $status_code
            );
        } else {
            return array(
                'success' => false,
                'error' => 'HTTP ' . $status_code . ': ' . $response_body,
                'status_code' => $status_code
            );
        }
    }
    
    /**
     * Make SQS API request
     */
    private function make_sqs_request($action, $params = array()) {
        $host = 'sqs.' . $this->region . '.amazonaws.com';
        $endpoint = 'https://' . $host . '/';
        
        $datetime = gmdate('Ymd\THis\Z');
        $date = substr($datetime, 0, 8);
        
        // Prepare parameters
        $params['Action'] = $action;
        $params['Version'] = '2012-11-05';
        
        $query_string = http_build_query($params);
        
        // Create canonical request
        $canonical_headers = "host:" . $host . "\n";
        $canonical_headers .= "x-amz-date:" . $datetime . "\n";
        
        $signed_headers = "host;x-amz-date";
        
        $canonical_request = "POST\n";
        $canonical_request .= "/\n";
        $canonical_request .= "\n";
        $canonical_request .= $canonical_headers . "\n";
        $canonical_request .= $signed_headers . "\n";
        $canonical_request .= hash('sha256', $query_string);
        
        // Create string to sign
        $credential_scope = $date . "/" . $this->region . "/sqs/aws4_request";
        $string_to_sign = "AWS4-HMAC-SHA256\n";
        $string_to_sign .= $datetime . "\n";
        $string_to_sign .= $credential_scope . "\n";
        $string_to_sign .= hash('sha256', $canonical_request);
        
        // Calculate signature
        $signature = $this->generate_signature_v4($date, $string_to_sign, 'sqs');
        
        // Create authorization header
        $authorization = "AWS4-HMAC-SHA256 Credential=" . $this->access_key . "/" . $credential_scope;
        $authorization .= ", SignedHeaders=" . $signed_headers;
        $authorization .= ", Signature=" . $signature;
        
        // Make HTTP request
        $args = array(
            'method' => 'POST',
            'timeout' => 60,
            'headers' => array(
                'Host' => $host,
                'X-Amz-Date' => $datetime,
                'Authorization' => $authorization,
                'Content-Type' => 'application/x-www-form-urlencoded'
            ),
            'body' => $query_string
        );
        
        $response = wp_remote_request($endpoint, $args);
        
        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($status_code >= 200 && $status_code < 300) {
            // Parse XML response
            $xml = simplexml_load_string($response_body);
            return $this->xml_to_array($xml);
        } else {
            throw new Exception('SQS API Error: HTTP ' . $status_code . ': ' . $response_body);
        }
    }
    
    /**
     * Make CloudFront API request
     */
    private function make_cloudfront_request($method, $resource, $data = null, $extra_headers = array()) {
        $host = 'cloudfront.amazonaws.com';
        $resource = ltrim($resource, '/');
        $path = '/2020-05-31/' . $resource;
        $endpoint = 'https://' . $host . $path;

        $datetime = gmdate('Ymd\THis\Z');
        $date = substr($datetime, 0, 8);

        $body = '';
        if (is_array($data)) {
            $body = $this->build_distribution_xml($data);
        } elseif (is_string($data)) {
            $body = $data;
        }

        $content_sha256 = hash('sha256', $body);

        // CloudFront canonical request
        $canonical_headers = "host:" . $host . "\n";
        $canonical_headers .= "x-amz-content-sha256:" . $content_sha256 . "\n";
        $canonical_headers .= "x-amz-date:" . $datetime . "\n";

        $signed_headers = "host;x-amz-content-sha256;x-amz-date";

        $canonical_request = $method . "\n";
        $canonical_request .= $path . "\n";
        $canonical_request .= "\n";
        $canonical_request .= $canonical_headers . "\n";
        $canonical_request .= $signed_headers . "\n";
        $canonical_request .= $content_sha256;

        // String to sign
        $credential_scope = $date . "/" . $this->region . "/cloudfront/aws4_request";
        $string_to_sign = "AWS4-HMAC-SHA256\n";
        $string_to_sign .= $datetime . "\n";
        $string_to_sign .= $credential_scope . "\n";
        $string_to_sign .= hash('sha256', $canonical_request);

        // Generate signature
        $signature = $this->generate_signature_v4($date, $string_to_sign, 'cloudfront');

        // Authorization header
        $authorization = "AWS4-HMAC-SHA256 Credential=" . $this->access_key . "/" . $credential_scope;
        $authorization .= ", SignedHeaders=" . $signed_headers;
        $authorization .= ", Signature=" . $signature;

        $headers = array(
            'Host' => $host,
            'X-Amz-Date' => $datetime,
            'X-Amz-Content-SHA256' => $content_sha256,
            'Authorization' => $authorization
        );

        if ($body !== '') {
            $headers['Content-Type'] = 'application/xml';
        }

        if (!empty($extra_headers)) {
            $headers = array_merge($headers, $extra_headers);
        }

        $args = array(
            'method' => $method,
            'timeout' => 60,
            'headers' => $headers,
            'body' => $body
        );

        $response = wp_remote_request($endpoint, $args);

        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($status_code >= 200 && $status_code < 300) {
            $parsed = $this->parse_cloudfront_xml($response_body);
            $parsed['success'] = true;
            $parsed['raw_body'] = $response_body;
            $parsed['headers'] = wp_remote_retrieve_headers($response);
            return $parsed;
        }

        throw new Exception('CloudFront API Error: HTTP ' . $status_code . ': ' . $response_body);
    }

private function build_distribution_xml($config) {
    $xml = '<?xml version="1.0" encoding="UTF-8"?>';
    $xml .= '<DistributionConfig xmlns="http://cloudfront.amazonaws.com/doc/2020-05-31/">';
    $xml .= '<CallerReference>' . $config['CallerReference'] . '</CallerReference>';
    $xml .= '<Comment>' . $config['Comment'] . '</Comment>';
    $xml .= '<Enabled>' . ($config['Enabled'] ? 'true' : 'false') . '</Enabled>';
    
    $xml .= '<Origins>';
    $xml .= '<Quantity>1</Quantity>';
    $xml .= '<Items>';
    $xml .= '<member>';
    $xml .= '<Id>' . $config['Origins']['Items'][0]['Id'] . '</Id>';
    $xml .= '<DomainName>' . $config['Origins']['Items'][0]['DomainName'] . '</DomainName>';
    $xml .= '<S3OriginConfig><OriginAccessIdentity></OriginAccessIdentity></S3OriginConfig>';
    $xml .= '</member>';
    $xml .= '</Items>';
    $xml .= '</Origins>';
    
    $xml .= '<DefaultCacheBehavior>';
    $xml .= '<TargetOriginId>' . $config['DefaultCacheBehavior']['TargetOriginId'] . '</TargetOriginId>';
    $xml .= '<ViewerProtocolPolicy>' . $config['DefaultCacheBehavior']['ViewerProtocolPolicy'] . '</ViewerProtocolPolicy>';
    $xml .= '<MinTTL>0</MinTTL>';
    $xml .= '<DefaultTTL>86400</DefaultTTL>';
    $xml .= '<MaxTTL>31536000</MaxTTL>';
    $xml .= '<ForwardedValues><QueryString>false</QueryString><Cookies><Forward>none</Forward></Cookies></ForwardedValues>';
    $xml .= '<TrustedSigners><Enabled>false</Enabled><Quantity>0</Quantity></TrustedSigners>';
    $xml .= '</DefaultCacheBehavior>';
    $xml .= '</DistributionConfig>';
    
    return $xml;
}

private function parse_cloudfront_xml($xml) {
    $data = simplexml_load_string($xml);
    if ($data === false) {
        return array();
    }
    $root = $data->getName();
    $payload = json_decode(json_encode($data), true);
    return array($root => $payload);
}
    /**
     * Generate AWS Signature Version 4
     */
    private function generate_signature_v4($date, $string_to_sign, $service) {
        $date_key = hash_hmac('sha256', $date, 'AWS4' . $this->secret_key, true);
        $region_key = hash_hmac('sha256', $this->region, $date_key, true);
        $service_key = hash_hmac('sha256', $service, $region_key, true);
        $signing_key = hash_hmac('sha256', 'aws4_request', $service_key, true);
        
        return hash_hmac('sha256', $string_to_sign, $signing_key);
    }
    
    /**
     * Convert XML to array
     */
    private function xml_to_array($xml) {
        return json_decode(json_encode($xml), true);
    }
}