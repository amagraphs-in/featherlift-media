<?php
/**
 * SQS Queue Processor for FeatherLift Media Plugin
 * Handles processing of upload and download operations
 */

class Enhanced_S3_SQS_Processor {
    private $aws_sdk;
    private $options;
    private $logs_table;
    
    public function __construct($aws_sdk, $options) {
        global $wpdb;
        $this->aws_sdk = $aws_sdk;
        $this->options = $options;
        $this->logs_table = $wpdb->prefix . 'amagraphs_s3_logs';
    }
}

/**
 * Queue Management Helper Class
 */
class Enhanced_S3_Queue_Manager {
    private $aws_sdk;
    private $options;
    private $processor;
    
    public function __construct($aws_sdk, $options) {
        $this->aws_sdk = $aws_sdk;
        $this->options = $options;
        $this->processor = new Enhanced_S3_SQS_Processor($aws_sdk, $options);
    }
    
    /**
     * Queue an upload operation
     */
    public function queue_upload($attachment_id, $context = array()) {
        global $wpdb;
        
        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            throw new Exception('File does not exist');
        }
        
        $file_name = basename($file_path);
        $file_size = filesize($file_path);
        $job_meta = $this->prepare_job_meta($context, 'upload');
        
        // Insert log entry
        $logs_table = $wpdb->prefix . 'amagraphs_s3_logs';
        $wpdb->insert(
            $logs_table,
            array(
                'attachment_id' => $attachment_id,
                'operation_type' => 'upload',
                'status' => 'requested',
                'file_name' => $file_name,
                'file_size' => $file_size,
                'job_meta' => !empty($job_meta) ? maybe_serialize($job_meta) : null,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s', '%d', '%s', '%s')
        );
        
        $log_id = $wpdb->insert_id;
        
        // Send message to SQS
        $message = array(
            'operation' => 'upload',
            'log_id' => $log_id,
            'attachment_id' => $attachment_id,
            'file_path' => $file_path,
                'timestamp' => time()
        );
        
        $result = $this->aws_sdk->send_sqs_message($this->options['sqs_queue_url'], $message);
        
        if (!$result['success']) {
            // Update log with error
            $wpdb->update(
                $logs_table,
                array(
                    'status' => 'failed',
                    'error_message' => 'Failed to queue: ' . $result['error'],
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $log_id)
            );
            
            throw new Exception('Failed to queue upload: ' . $result['error']);
        }
        
        return $log_id;
    }
    
    /**
     * Queue a download operation
     */
    public function queue_download($attachment_id, $context = array()) {
        global $wpdb;
        
        $s3_key = get_post_meta($attachment_id, 'enhanced_s3_key', true);
        if (!$s3_key) {
            throw new Exception('File is not on S3');
        }
        
        $file_name = basename($s3_key);
        $job_meta = $this->prepare_job_meta($context, 'download');
        
        // Insert log entry
        $logs_table = $wpdb->prefix . 'amagraphs_s3_logs';
        $wpdb->insert(
            $logs_table,
            array(
                'attachment_id' => $attachment_id,
                'operation_type' => 'download',
                'status' => 'requested',
                'file_name' => $file_name,
                's3_key' => $s3_key,
                'job_meta' => !empty($job_meta) ? maybe_serialize($job_meta) : null,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        $log_id = $wpdb->insert_id;
        
        // Send message to SQS
        $message = array(
            'operation' => 'download',
            'log_id' => $log_id,
            'attachment_id' => $attachment_id,
            's3_key' => $s3_key,
            'timestamp' => time()
        );
        
        $result = $this->aws_sdk->send_sqs_message($this->options['sqs_queue_url'], $message);
        
        if (!$result['success']) {
            // Update log with error
            $wpdb->update(
                $logs_table,
                array(
                    'status' => 'failed',
                    'error_message' => 'Failed to queue: ' . $result['error'],
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $log_id)
            );
            
            throw new Exception('Failed to queue download: ' . $result['error']);
        }
        
        return $log_id;
    }
    
    /**
     * Get queue statistics
     */
    public function get_queue_stats() {
        global $wpdb;
        
        $logs_table = $wpdb->prefix . 'amagraphs_s3_logs';
        
        $stats = array(
            'total_operations' => 0,
            'pending_operations' => 0,
            'completed_operations' => 0,
            'failed_operations' => 0,
            'in_progress_operations' => 0
        );
        
        // Get total operations
        $stats['total_operations'] = $wpdb->get_var("SELECT COUNT(*) FROM {$logs_table}");
        
        // Get operations by status
        $status_counts = $wpdb->get_results("
            SELECT status, COUNT(*) as count 
            FROM {$logs_table} 
            GROUP BY status
        ");
        
        foreach ($status_counts as $status_count) {
            switch ($status_count->status) {
                case 'requested':
                    $stats['pending_operations'] = $status_count->count;
                    break;
                case 'in_progress':
                    $stats['in_progress_operations'] = $status_count->count;
                    break;
                case 'completed':
                    $stats['completed_operations'] = $status_count->count;
                    break;
                case 'failed':
                    $stats['failed_operations'] = $status_count->count;
                    break;
            }
        }
        
        return $stats;
    }
    
    /**
     * Retry failed operations
     */
    public function retry_failed_operations($limit = 10) {
        global $wpdb;
        
        $logs_table = $wpdb->prefix . 'amagraphs_s3_logs';
        
        $failed_operations = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$logs_table} 
            WHERE status = 'failed' 
            ORDER BY created_at DESC 
            LIMIT %d
        ", $limit));
        
        $retried_count = 0;
        
        foreach ($failed_operations as $operation) {
            try {
                if ($operation->operation_type === 'upload') {
                    $this->queue_upload($operation->attachment_id, array(
                        'source' => 'retry',
                        'original_log' => $operation->id
                    ));
                } else {
                    $this->queue_download($operation->attachment_id, array(
                        'source' => 'retry',
                        'original_log' => $operation->id
                    ));
                }
                
                // Mark original operation as retried
                $wpdb->update(
                    $logs_table,
                    array(
                        'status' => 'retried',
                        'updated_at' => current_time('mysql')
                    ),
                    array('id' => $operation->id)
                );
                
                $retried_count++;
                
            } catch (Exception $e) {
                error_log('Failed to retry operation ' . $operation->id . ': ' . $e->getMessage());
            }
        }
        
        return $retried_count;
    }
    
    /**
     * Clean old logs
     */
    public function clean_old_logs($days = 30) {
        global $wpdb;
        
        $logs_table = $wpdb->prefix . 'amagraphs_s3_logs';
        
        $deleted = $wpdb->query($wpdb->prepare("
            DELETE FROM {$logs_table} 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)
            AND status IN ('completed', 'failed')
        ", $days));
        
        return $deleted;
    }

    /**
     * Prepare contextual metadata for queued jobs
     */
    private function prepare_job_meta($context = array(), $operation_type = '') {
        $meta = array();
        if (!empty($operation_type)) {
            $meta['operation'] = sanitize_key($operation_type);
        }
        if (!empty($context['source'])) {
            $meta['source'] = sanitize_key($context['source']);
        }
        if (isset($context['post_id'])) {
            $meta['post_id'] = absint($context['post_id']);
        }
        if (!empty($context['batch'])) {
            $meta['batch'] = sanitize_key($context['batch']);
        }
        if (isset($context['initiator'])) {
            $meta['initiator'] = absint($context['initiator']);
        }
        if (!empty($context['notes'])) {
            $meta['notes'] = sanitize_text_field($context['notes']);
        }
        if (isset($context['original_log'])) {
            $meta['previous_log'] = absint($context['original_log']);
        }
        return array_filter($meta, function($value) {
            return $value !== null && $value !== '';
        });
    }
    
    /**
     * Process SQS queue messages
     */
    public function process_queue() {
        if (empty($this->options['sqs_queue_url'])) {
            error_log('SQS queue URL is empty');
            return;
        }
        
        try {
            error_log('Receiving messages from SQS queue: ' . $this->options['sqs_queue_url']);
            $processed = 0;
            do {
                $result = $this->aws_sdk->receive_sqs_messages($this->options['sqs_queue_url'], 10);
                
                if (!$result['success']) {
                    error_log('SQS receive failed: ' . $result['error']);
                    break;
                }
                
                if (empty($result['messages'])) {
                    if ($processed === 0) {
                        error_log('No messages in SQS queue');
                    }
                    break;
                }
                
                foreach ($result['messages'] as $message) {
                    $this->process_message($message);
                    $processed++;
                }
            } while (!empty($result['messages']) && $processed < 100);
            
        } catch (Exception $e) {
            error_log('FeatherLift Media SQS Processor Error: ' . $e->getMessage());
        }
    }

    private function update_log_status($log_id, $status, $error_message = null, $additional_data = array()) {
        global $wpdb;
        
        $logs_table = $wpdb->prefix . 'amagraphs_s3_logs';
        
        $update_data = array(
            'status' => $status,
            'updated_at' => current_time('mysql')
        );
        
        if ($status === 'in_progress' && empty($additional_data)) {
            $update_data['started_at'] = current_time('mysql');
        }
        
        if ($status === 'completed') {
            $update_data['completed_at'] = current_time('mysql');
            
            if (isset($additional_data['s3_key'])) {
                $update_data['s3_key'] = $additional_data['s3_key'];
            }
            
            if (isset($additional_data['file_size'])) {
                $update_data['file_size'] = $additional_data['file_size'];
            }
        }
        
        if ($status === 'failed' && $error_message) {
            $update_data['error_message'] = $error_message;
        }

        if (!empty($additional_data['job_meta'])) {
            $existing_meta = $wpdb->get_var($wpdb->prepare(
                "SELECT job_meta FROM {$logs_table} WHERE id = %d",
                $log_id
            ));
            $stored_meta = $existing_meta ? maybe_unserialize($existing_meta) : array();
            if (!is_array($stored_meta)) {
                $stored_meta = array();
            }
            $merged = array_merge($stored_meta, $additional_data['job_meta']);
            $update_data['job_meta'] = maybe_serialize($merged);
        }

        $formats = array();
        foreach ($update_data as $column => $value) {
            switch ($column) {
                case 'file_size':
                    $formats[] = '%d';
                    break;
                default:
                    $formats[] = '%s';
            }
        }
        
        $wpdb->update(
            $logs_table,
            $update_data,
            array('id' => $log_id),
            $formats,
            array('%d')
        );
    }
    
    private function handle_upload($message_body, $log_id) {
        try {
            $attachment_id = $message_body['attachment_id'];
            $file_path = $message_body['file_path'];
            
            if (!file_exists($file_path)) {
                throw new Exception('File does not exist: ' . $file_path);
            }
            
            $original_file_size = filesize($file_path);
            $processing_path = $file_path;
            $temporary_files = array();
            $compression_results = null;
            $resize_results = null;
            
            if ($this->should_resize_before_upload($attachment_id)) {
                $resize_results = $this->resize_image_for_upload($attachment_id, $processing_path);
                if ($resize_results['success']) {
                    $processing_path = $resize_results['file_path'];
                    $temporary_files[] = $processing_path;
                    $this->maybe_update_metadata_dimensions($attachment_id, $resize_results);
                } elseif (isset($resize_results['error'])) {
                    error_log('FeatherLift Media: Resize failed for attachment ' . $attachment_id . ': ' . $resize_results['error']);
                }
            }
            
            // Compress image if enabled and it's an image
            if (isset($this->options['compress_images']) && $this->options['compress_images'] && wp_attachment_is_image($attachment_id)) {
                $compressor_path = dirname(__FILE__) . '/../includes/image-compressor.php';
                if (file_exists($compressor_path)) {
                    require_once $compressor_path;
                    $compressor = new Enhanced_S3_Image_Compressor($this->options);
                    
                    // Create temporary compressed file
                    $temp_compressed = tempnam(sys_get_temp_dir(), 's3_compressed_');
                    $compression_result = $compressor->compress_image($processing_path, $temp_compressed);
                    
                    if ($compression_result['success']) {
                        $processing_path = $temp_compressed;
                        $temporary_files[] = $temp_compressed;
                        $compression_results = $compression_result;
                        
                        // Log compression results
                        error_log("FeatherLift Media: Compressed attachment {$attachment_id} - Original: " . 
                            $this->format_bytes($compression_result['original_size']) . 
                            ", Compressed: " . $this->format_bytes($compression_result['compressed_size']) . 
                            ", Savings: {$compression_result['savings_percent']}% using {$compression_result['service_used']}");
                    } else {
                        // Compression failed, use original file
                        error_log("FeatherLift Media: Compression failed for attachment {$attachment_id}: " . $compression_result['error']);
                    }
                }
            }
            
            // Generate S3 key for main image
            $upload_dir = wp_upload_dir();
            $base_dir = $upload_dir['basedir'];
            $relative_path = str_replace($base_dir, '', $file_path);
            $relative_path = ltrim($relative_path, '/');
            $s3_key = $this->options['s3_prefix'] . $relative_path;
            
            $mime_type = get_post_mime_type($attachment_id) ?: 'application/octet-stream';
            $final_file_size = filesize($processing_path);
            
            // Upload main image to S3 (compressed or original)
            $upload_result = $this->aws_sdk->upload_file_to_s3(
                $processing_path,
                $this->options['bucket_name'],
                $s3_key,
                $mime_type
            );
            
            // Clean up temporary compressed file
            foreach ($temporary_files as $temp_file) {
                if ($temp_file !== $file_path && file_exists($temp_file)) {
                    unlink($temp_file);
                }
            }
            
            if (!$upload_result['success']) {
                throw new Exception('S3 upload failed: ' . $upload_result['error']);
            }
            
            // Upload thumbnails if this is an image
            if (wp_attachment_is_image($attachment_id) && isset($this->options['upload_thumbnails']) && $this->options['upload_thumbnails']) {
                $this->upload_thumbnails($attachment_id, $file_path, $s3_key);
            }
            
            // Save S3 metadata
            update_post_meta($attachment_id, 'enhanced_s3_key', $s3_key);
            update_post_meta($attachment_id, 'enhanced_s3_bucket', $this->options['bucket_name']);
            $s3_url = $this->build_s3_url($s3_key);
            if ($s3_url) {
                update_post_meta($attachment_id, 'enhanced_s3_url', esc_url_raw($s3_url));
            }
            
            // Save compression metadata if compression was used
            if ($compression_results) {
                update_post_meta($attachment_id, 'enhanced_s3_compressed', '1');
                update_post_meta($attachment_id, 'enhanced_s3_original_size', $compression_results['original_size']);
                update_post_meta($attachment_id, 'enhanced_s3_compressed_size', $compression_results['compressed_size']);
                update_post_meta($attachment_id, 'enhanced_s3_savings_percent', $compression_results['savings_percent']);
                update_post_meta($attachment_id, 'enhanced_s3_compression_service', $compression_results['service_used']);
            }
            
            // Replace URLs in content to point to S3/CloudFront
            $this->replace_urls_in_content($attachment_id, $s3_key);
            
            // Update attachment GUID to S3 URL
            $this->update_attachment_guid($attachment_id, $s3_key);
            
            // Delete local files if enabled
            if (isset($this->options['auto_delete_local']) && $this->options['auto_delete_local']) {
                $this->delete_local_files($attachment_id, $file_path);
            }
            
            // Prepare completion data
            $completion_data = array(
                's3_key' => $s3_key,
                'file_size' => $final_file_size,
                'original_size' => $original_file_size
            );
            
            if ($compression_results) {
                $completion_data['compressed'] = true;
                $completion_data['compression_savings'] = $compression_results['savings_percent'];
                $completion_data['compression_service'] = $compression_results['service_used'];
            }
            
            // Update log as completed
            $this->update_log_status($log_id, 'completed', null, $completion_data);
            
            // Log successful upload
            $size_info = $compression_results ? 
                " (compressed from " . $this->format_bytes($original_file_size) . " to " . $this->format_bytes($final_file_size) . ")" : 
                " (" . $this->format_bytes($final_file_size) . ")";
            
            error_log("FeatherLift Media: Successfully uploaded attachment {$attachment_id} to S3 as {$s3_key}{$size_info}");
            
        } catch (Exception $e) {
            // Clean up temporary compressed file on error
            if (isset($compressed_path) && $compressed_path !== $file_path && file_exists($compressed_path)) {
                unlink($compressed_path);
            }
            
            error_log("FeatherLift Media: Upload failed for attachment {$attachment_id}: " . $e->getMessage());
            $this->update_log_status($log_id, 'failed', $e->getMessage());
        }
    }

    /**
     * Helper method to format bytes for logging
     */
    private function format_bytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }

    private function should_resize_before_upload($attachment_id) {
        return isset($this->options['auto_resize_images']) && $this->options['auto_resize_images'] && wp_attachment_is_image($attachment_id);
    }

    private function resize_image_for_upload($attachment_id, $file_path) {
        $max_width = isset($this->options['resize_max_width']) ? intval($this->options['resize_max_width']) : 0;
        $max_height = isset($this->options['resize_max_height']) ? intval($this->options['resize_max_height']) : 0;
        
        if ($max_width <= 0 && $max_height <= 0) {
            return array('success' => false);
        }

        if (!function_exists('wp_get_image_editor')) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }
        if (!function_exists('wp_tempnam')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
        
        $editor = wp_get_image_editor($file_path);
        if (is_wp_error($editor)) {
            return array('success' => false, 'error' => $editor->get_error_message());
        }
        
        $resize_result = $editor->resize($max_width > 0 ? $max_width : null, $max_height > 0 ? $max_height : null, false);
        if (is_wp_error($resize_result)) {
            return array('success' => false, 'error' => $resize_result->get_error_message());
        }
        
        $temp_path = wp_tempnam(basename($file_path));
        if (!$temp_path) {
            $temp_path = tempnam(sys_get_temp_dir(), 's3_resized_');
        }
        
        if (!$temp_path) {
            return array('success' => false, 'error' => 'Unable to create temporary file for resizing');
        }
        
        $saved = $editor->save($temp_path);
        if (is_wp_error($saved)) {
            unlink($temp_path);
            return array('success' => false, 'error' => $saved->get_error_message());
        }
        
        return array(
            'success' => true,
            'file_path' => $temp_path,
            'width' => $saved['width'] ?? null,
            'height' => $saved['height'] ?? null
        );
    }

    private function maybe_update_metadata_dimensions($attachment_id, $resize_results) {
        if (empty($resize_results['width']) || empty($resize_results['height'])) {
            return;
        }
        $metadata = wp_get_attachment_metadata($attachment_id);
        if (empty($metadata)) {
            return;
        }
        $metadata['width'] = $resize_results['width'];
        $metadata['height'] = $resize_results['height'];
        wp_update_attachment_metadata($attachment_id, $metadata);
    }

    private function build_s3_url($s3_key) {
        if (empty($s3_key)) {
            return '';
        }
        $key = ltrim($s3_key, '/');
        if (!empty($this->options['use_cloudfront']) && !empty($this->options['cloudfront_domain'])) {
            return 'https://' . $this->options['cloudfront_domain'] . '/' . $key;
        }
        if (empty($this->options['bucket_name'])) {
            return '';
        }
        $region = $this->options['region'] ?? 'us-east-1';
        $endpoint = $region === 'us-east-1' ? 's3.amazonaws.com' : 's3.' . $region . '.amazonaws.com';
        return 'https://' . $this->options['bucket_name'] . '.' . $endpoint . '/' . $key;
    }
    
    private function handle_download($message_body, $log_id) {
        try {
            $attachment_id = $message_body['attachment_id'];
            $s3_key = $message_body['s3_key'];
            
            if (!$s3_key) {
                throw new Exception('S3 key not provided');
            }
            
            // Generate local file path
            $upload_dir = wp_upload_dir();
            $relative_path = str_replace($this->options['s3_prefix'], '', $s3_key);
            $local_path = $upload_dir['basedir'] . '/' . $relative_path;
            
            // Create directory if needed
            $dir = dirname($local_path);
            if (!is_dir($dir)) {
                wp_mkdir_p($dir);
            }
            
            // Download main image from S3
            $download_result = $this->aws_sdk->download_file_from_s3(
                $this->options['bucket_name'],
                $s3_key,
                $local_path
            );
            
            if (!$download_result['success']) {
                throw new Exception('S3 download failed: ' . $download_result['error']);
            }
            
            // Download thumbnails if this is an image
            if (wp_attachment_is_image($attachment_id)) {
                $this->download_thumbnails($attachment_id, $s3_key, $local_path);
            }
            
            // Update attachment file path
            update_attached_file($attachment_id, $local_path);
            
            // Remove S3 metadata
            delete_post_meta($attachment_id, 'enhanced_s3_key');
            delete_post_meta($attachment_id, 'enhanced_s3_bucket');
            delete_post_meta($attachment_id, 'enhanced_s3_url');
            
            // Regenerate thumbnails for images (in case some thumbnails were missing)
            if (wp_attachment_is_image($attachment_id)) {
                $metadata = wp_generate_attachment_metadata($attachment_id, $local_path);
                wp_update_attachment_metadata($attachment_id, $metadata);
            }
            
            // Update attachment GUID back to local URL
            $local_url = wp_get_attachment_url($attachment_id);
            global $wpdb;
            $wpdb->update(
                $wpdb->posts,
                array('guid' => $local_url),
                array('ID' => $attachment_id)
            );
            
            // Update log as completed
            $this->update_log_status($log_id, 'completed', null, array(
                'local_path' => $local_path,
                'file_size' => $download_result['file_size']
            ));

            $completed = get_option('enhanced_s3_restore_completed_files', 0);
            update_option('enhanced_s3_restore_completed_files', $completed + 1);
            
            // Check if this was the last file
            $total_files = get_option('enhanced_s3_restore_total_files', 0);
            if ($total_files > 0 && ($completed + 1) >= $total_files) {
                $this->send_restore_completion_notification();
            }
            
        } catch (Exception $e) {
            $this->update_log_status($log_id, 'failed', $e->getMessage());
        }
    }

    private function send_restore_completion_notification() {
        // Get restore statistics
        $start_time = get_option('enhanced_s3_restore_start_time', 0);
        $total_files = get_option('enhanced_s3_restore_total_files', 0);
        $completed_files = get_option('enhanced_s3_restore_completed_files', 0);
        
        global $wpdb;
        $failed_count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}amagraphs_s3_logs 
            WHERE operation_type = 'download' 
            AND status = 'failed' 
            AND created_at > %s
        ", date('Y-m-d H:i:s', $start_time)));
        
        $duration = $start_time ? human_time_diff($start_time, time()) : 'unknown';
        
        // Send completion notification
        if (method_exists($this, 'send_restore_notification')) {
            // Call main plugin method
            $main_plugin = new Enhanced_S3_Media_Upload();
            $main_plugin->send_restore_notification('restore_completed', array(
                'downloaded' => $completed_files,
                'failed' => $failed_count,
                'duration' => $duration,
                'total_size' => $this->format_total_size($completed_files)
            ));
        }
        
        // Clean up tracking options
        delete_option('enhanced_s3_restore_start_time');
        delete_option('enhanced_s3_restore_total_files');
        delete_option('enhanced_s3_restore_completed_files');
    }

    private function format_total_size($file_count) {
        global $wpdb;
        
        $total_bytes = $wpdb->get_var("
            SELECT SUM(file_size) FROM {$wpdb->prefix}amagraphs_s3_logs 
            WHERE operation_type = 'download' 
            AND status = 'completed'
        ");
        
        if (!$total_bytes) return 'unknown';
        
        return $this->format_bytes($total_bytes);
    }

    /**
     * Process individual SQS message
     */
    private function process_message($message) {
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');
        }
        try {
            $message_body = json_decode($message['Body'], true);
            
            if (!$message_body) {
                throw new Exception('Invalid message body');
            }
            
            $log_id = $message_body['log_id'];
            $operation = $message_body['operation'];
            
            // Update log status to in_progress
            $this->update_log_status($log_id, 'in_progress');
            
            switch ($operation) {
                case 'upload':
                    $this->handle_upload($message_body, $log_id);
                    break;
                    
                case 'download':
                    $this->handle_download($message_body, $log_id);
                    break;
                    
                default:
                    throw new Exception('Unknown operation: ' . $operation);
            }
        
            // Delete message from queue after successful processing
            $this->aws_sdk->delete_sqs_message(
                $this->options['sqs_queue_url'],
                $message['ReceiptHandle']
            );
            
        } catch (Exception $e) {
            // Update log with error
            if (isset($log_id)) {
                $this->update_log_status($log_id, 'failed', $e->getMessage());
            }
            
            error_log('FeatherLift Media Message Processing Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Upload thumbnails to S3
     */
    private function upload_thumbnails($attachment_id, $main_file_path, $main_s3_key) {
        $metadata = wp_get_attachment_metadata($attachment_id);
        
        if (empty($metadata['sizes'])) {
            return;
        }
        
        $file_dir = dirname($main_file_path);
        $s3_dir = dirname($main_s3_key);
        
        foreach ($metadata['sizes'] as $size => $size_info) {
            if (!isset($size_info['file'])) {
                continue;
            }
            
            $thumb_path = $file_dir . '/' . $size_info['file'];
            
            if (!file_exists($thumb_path)) {
                continue;
            }
            
            $thumb_s3_key = $s3_dir . '/' . $size_info['file'];
            $thumb_mime = $size_info['mime-type'] ?? 'image/jpeg';
            
            $this->aws_sdk->upload_file_to_s3(
                $thumb_path,
                $this->options['bucket_name'],
                $thumb_s3_key,
                $thumb_mime
            );
            
            // Delete local thumbnail if auto-delete is enabled
            if (isset($this->options['auto_delete_local']) && $this->options['auto_delete_local']) {
                unlink($thumb_path);
            }
        }
    }

    /**
     * Download thumbnails from S3
     */
    private function download_thumbnails($attachment_id, $main_s3_key, $main_local_path) {
        $metadata = wp_get_attachment_metadata($attachment_id);
        
        if (empty($metadata['sizes'])) {
            return;
        }
        
        $local_dir = dirname($main_local_path);
        $s3_dir = dirname($main_s3_key);
        
        foreach ($metadata['sizes'] as $size => $size_info) {
            if (!isset($size_info['file'])) {
                continue;
            }
            
            $thumb_s3_key = $s3_dir . '/' . $size_info['file'];
            $thumb_local_path = $local_dir . '/' . $size_info['file'];
            
            // Download thumbnail from S3
            $download_result = $this->aws_sdk->download_file_from_s3(
                $this->options['bucket_name'],
                $thumb_s3_key,
                $thumb_local_path
            );
            
            // Continue even if some thumbnails fail to download
            if (!$download_result['success']) {
                error_log("Failed to download thumbnail {$thumb_s3_key}: " . $download_result['error']);
            }
        }
    }

    /**
     * Get URL variations for an attachment
     */
    private function get_url_variations($attachment_id, $base_url) {
        $variations = array($base_url);
        
        // Get attachment metadata for thumbnail URLs
        $metadata = wp_get_attachment_metadata($attachment_id);
        
        if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
            $upload_dir = wp_upload_dir();
            $base_url_path = str_replace($upload_dir['baseurl'], '', $base_url);
            $base_dir = dirname($base_url_path);
            
            foreach ($metadata['sizes'] as $size => $size_data) {
                if (isset($size_data['file'])) {
                    $thumbnail_url = $upload_dir['baseurl'] . $base_dir . '/' . $size_data['file'];
                    $variations[] = $thumbnail_url;
                }
            }
        }
        
        // Add protocol variations
        $additional_variations = array();
        foreach ($variations as $url) {
            if (strpos($url, 'https://') === 0) {
                $additional_variations[] = str_replace('https://', 'http://', $url);
            } elseif (strpos($url, 'http://') === 0) {
                $additional_variations[] = str_replace('http://', 'https://', $url);
            }
            
            // Add protocol-relative versions
            $additional_variations[] = str_replace(array('https://', 'http://'), '//', $url);
        }
        
        return array_unique(array_merge($variations, $additional_variations));
    }

    /**
     * Perform URL replacement
     */
    private function perform_url_replacement($old_url, $new_url) {
        global $wpdb;
        
        // Replace in post content
        $wpdb->query($wpdb->prepare("
            UPDATE {$wpdb->posts} 
            SET post_content = REPLACE(post_content, %s, %s)
            WHERE post_content LIKE %s
        ", $old_url, $new_url, '%' . $wpdb->esc_like($old_url) . '%'));
        
        // Replace in post excerpts
        $wpdb->query($wpdb->prepare("
            UPDATE {$wpdb->posts} 
            SET post_excerpt = REPLACE(post_excerpt, %s, %s)
            WHERE post_excerpt LIKE %s
        ", $old_url, $new_url, '%' . $wpdb->esc_like($old_url) . '%'));
        
        // Replace in post meta
        $wpdb->query($wpdb->prepare("
            UPDATE {$wpdb->postmeta} 
            SET meta_value = REPLACE(meta_value, %s, %s)
            WHERE meta_value LIKE %s
        ", $old_url, $new_url, '%' . $wpdb->esc_like($old_url) . '%'));
        
        // Replace in options
        $wpdb->query($wpdb->prepare("
            UPDATE {$wpdb->options} 
            SET option_value = REPLACE(option_value, %s, %s)
            WHERE option_value LIKE %s
        ", $old_url, $new_url, '%' . $wpdb->esc_like($old_url) . '%'));
    }

    /**
     * Replace URLs in content
     */
    private function replace_urls_in_content($attachment_id, $s3_key) {
        global $wpdb;
        
        // Get old and new URLs
        $old_url = wp_get_attachment_url($attachment_id);
        $new_url = $this->get_s3_url($s3_key);
        
        if ($old_url === $new_url) {
            return;
        }
        
        // Get all URL variations (including thumbnails)
        $url_variations = $this->get_url_variations($attachment_id, $old_url);
        
        foreach ($url_variations as $old_variation) {
            // Calculate new URL for this variation
            $relative_path = str_replace(wp_upload_dir()['baseurl'], '', $old_variation);
            $new_variation = $this->get_s3_url($this->options['s3_prefix'] . ltrim($relative_path, '/'));
            
            // Perform the replacement
            $this->perform_url_replacement($old_variation, $new_variation);
        }
        
        // Clear caches
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
    }
    
    /**
     * Replace URLs back to local
     */
    private function replace_urls_back_to_local($attachment_id, $s3_key) {
        global $wpdb;
        
        $old_url = $this->get_s3_url($s3_key);
        $new_url = wp_get_attachment_url($attachment_id);
        
        if ($old_url === $new_url) {
            return;
        }
        
        // Similar URL replacement logic but in reverse
        $this->perform_url_replacement($old_url, $new_url);
    }
    
    /**
     * Delete local files
     */
    private function delete_local_files($attachment_id, $main_file_path) {
        // Delete main file
        if (file_exists($main_file_path)) {
            unlink($main_file_path);
        }
        
        // Delete thumbnails
        $metadata = wp_get_attachment_metadata($attachment_id);
        
        if (!empty($metadata['sizes'])) {
            $file_dir = dirname($main_file_path);
            
            foreach ($metadata['sizes'] as $size_info) {
                if (isset($size_info['file'])) {
                    $thumb_path = $file_dir . '/' . $size_info['file'];
                    if (file_exists($thumb_path)) {
                        unlink($thumb_path);
                    }
                }
            }
        }
    }

    /**
     * Regenerate thumbnails
     */
    private function regenerate_thumbnails($attachment_id) {
        $file_path = get_attached_file($attachment_id);
        
        if (!$file_path || !file_exists($file_path)) {
            return;
        }
        
        // Use WordPress function to regenerate thumbnails
        $metadata = wp_generate_attachment_metadata($attachment_id, $file_path);
        wp_update_attachment_metadata($attachment_id, $metadata);
    }
    
    /**
     * Update attachment GUID
     */
    private function update_attachment_guid($attachment_id, $s3_key) {
        $new_url = $this->get_s3_url($s3_key);
        
        global $wpdb;
        $wpdb->update(
            $wpdb->posts,
            array('guid' => $new_url),
            array('ID' => $attachment_id)
        );
    }
    
    /**
     * Update attachment GUID back to local
     */
    private function update_attachment_guid_to_local($attachment_id) {
        $local_url = wp_get_attachment_url($attachment_id);
        
        global $wpdb;
        $wpdb->update(
            $wpdb->posts,
            array('guid' => $local_url),
            array('ID' => $attachment_id)
        );
    }
    
    /**
     * Get S3 URL
     */
    private function get_s3_url($s3_key) {
        if (isset($this->options['use_cloudfront']) && $this->options['use_cloudfront'] && !empty($this->options['cloudfront_domain'])) {
            return 'https://' . $this->options['cloudfront_domain'] . '/' . $s3_key;
        } else {
            $region = isset($this->options['region']) ? $this->options['region'] : 'us-east-1';
            return 'https://' . $this->options['bucket_name'] . '.s3.' . $region . '.amazonaws.com/' . $s3_key;
        }
    }
}