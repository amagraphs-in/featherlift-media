<?php
/**
 * Plugin Name: FeatherLift Media
 * Plugin URI: https://amagraphs.com
 * Description: Advanced WordPress media upload to Amazon S3 with SQS queue management and automatic bucket/CloudFront creation
 * Version: 1.1.0
 * Author: Amagraphs
 * Author URI: https://amagraphs.com
 * License: GPL2
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Load release token from dedicated include to keep bootstrap lean.
$release_token_file = __DIR__ . '/includes/release-token.php';
if (file_exists($release_token_file)) {
    require_once $release_token_file;
}

// Add cron schedule for every minute
add_filter('cron_schedules', function($schedules) {
    $schedules['every_30_seconds'] = array(
        'interval' => 30,
        'display' => 'Every 30 Seconds'
    );
    return $schedules;
});

class Enhanced_S3_Media_Upload {
    private $version = '1.1.0';
    private $options;
    private $db_version = '2.1.0';
    private $suppress_settings_reactions = false;
    
    // AWS Configuration
    private $access_key;
    private $secret_key;
    private $region;
    private $bucket_name;
    private $s3_endpoint;
    private $s3_prefix;
    private $use_cloudfront;
    private $cloudfront_domain;
    private $cloudfront_distribution_id;
    private $sqs_queue_url;
    private $auto_delete_local;
    private $upload_thumbnails;
    private $auto_upload_new_files;
    private $compress_images;
    private $compression_service;
    private $compression_quality;
    private $tinypng_api_key;
    private $sensitive_fields = array(
        'access_key',
        'secret_key',
        'tinypng_api_key',
        'openai_api_key',
        'anthropic_api_key',
        'custom_ai_api_key'
    );
    private $bucket_autoname_strategy;
    private $preserve_bucket_permissions;
    private $optimize_media;
    private $offload_media;
    private $auto_resize_images;
    private $resize_max_width;
    private $resize_max_height;
    private $default_resize_cap = 2560;
    private $ai_alt_enabled;
    private $ai_agent;
    private $ai_model;
    private $ai_site_brief;
    private $ai_skip_existing_alt;
    private $openai_api_key;
    private $anthropic_api_key;
    private $custom_ai_api_key;
    private $custom_ai_endpoint;
    private $intent_committed = false;
    private $ai_features_available = false;
    
    // Database table names
    private $logs_table;
    
    // AWS SDK instance
    private $aws_sdk;
    public $queue_manager;
    
    public function __construct() {
        global $wpdb;
        $this->logs_table = $wpdb->prefix . 'amagraphs_s3_logs';
        
        // Load options
        $this->load_options();
        $this->maybe_upgrade_database();
        
        // Initialize AWS SDK only if configured
        if ($this->is_configured()) {
            $this->init_aws_components();
        }
        
        // Initialize hooks
        $this->init_hooks();
        add_action('update_option_enhanced_s3_settings', array($this, 'after_settings_updated'), 10, 3);
        add_action('admin_notices', array($this, 'render_setup_notice'));
        
        // Create database tables on activation
        register_activation_hook(__FILE__, array($this, 'create_tables'));
        
        // Add plugin deactivation protection
        register_deactivation_hook(__FILE__, array($this, 'prevent_deactivation'));

    }
    
    private function init_aws_components() {
        $includes_dir = plugin_dir_path(__FILE__) . 'includes/';
        
        if (file_exists($includes_dir . 'aws-sdk-integration.php')) {
            require_once $includes_dir . 'aws-sdk-integration.php';
            $this->aws_sdk = new Enhanced_S3_AWS_SDK($this->access_key, $this->secret_key, $this->region);
        }
        
        if (file_exists($includes_dir . 'sqs-processor.php') && $this->aws_sdk) {
            require_once $includes_dir . 'sqs-processor.php';
            $this->queue_manager = new Enhanced_S3_Queue_Manager($this->aws_sdk, $this->get_runtime_options());
        }
    }
    
    private function load_options() {
        $this->options = get_option('enhanced_s3_settings', array());
        $this->auto_upload_new_files = $this->get_option('auto_upload_new_files', false);
        $this->access_key = $this->get_option('access_key', '');
        $this->secret_key = $this->get_option('secret_key', '');
        $this->region = $this->get_option('region', 'us-east-1');
        $this->bucket_name = $this->get_option('bucket_name', '');
        $this->s3_prefix = $this->get_option('s3_prefix', 'wp-content/uploads/');
        $this->use_cloudfront = $this->get_option('use_cloudfront', false);
        $this->cloudfront_domain = $this->get_option('cloudfront_domain', '');
        $this->cloudfront_distribution_id = $this->get_option('cloudfront_distribution_id', '');
        $this->sqs_queue_url = $this->get_option('sqs_queue_url', '');
        $this->auto_delete_local = $this->get_option('auto_delete_local', false);
        $this->upload_thumbnails = $this->get_option('upload_thumbnails', true);
        $this->compress_images = $this->get_option('compress_images', false);
        $this->compression_service = $this->get_option('compression_service', 'php_native');
        $this->compression_quality = $this->get_option('compression_quality', 85);
        $this->tinypng_api_key = $this->get_option('tinypng_api_key', '');
        $this->bucket_autoname_strategy = $this->get_option('bucket_autoname_strategy', 'file');
        $this->preserve_bucket_permissions = (bool) $this->get_option('preserve_bucket_permissions', true);
        $this->optimize_media = (bool) $this->get_option('optimize_media', false);
        $this->offload_media = (bool) $this->get_option('offload_media', false);
        $this->intent_committed = array_key_exists('optimize_media', $this->options) || array_key_exists('offload_media', $this->options);
        $this->auto_resize_images = (bool) $this->get_option('auto_resize_images', false);
        $this->resize_max_width = intval($this->get_option('resize_max_width', $this->default_resize_cap));
        $this->resize_max_height = intval($this->get_option('resize_max_height', $this->default_resize_cap));
        $stored_ai_enabled = (bool) $this->get_option('ai_alt_enabled', false);
        $this->ai_features_available = (bool) apply_filters('enhanced_s3_ai_ui_enabled', false);
        $this->ai_alt_enabled = $this->ai_features_available ? $stored_ai_enabled : false;
        $this->ai_agent = $this->get_option('ai_agent', 'openai');
        $this->ai_model = $this->get_option('ai_model', 'gpt-4o-mini');
        $this->ai_site_brief = $this->get_option('ai_site_brief', get_bloginfo('description'));
        $this->ai_skip_existing_alt = (bool) $this->get_option('ai_skip_existing_alt', true);
        $this->custom_ai_endpoint = $this->get_option('custom_ai_endpoint', '');
        $this->openai_api_key = $this->get_option('openai_api_key', '');
        $this->anthropic_api_key = $this->get_option('anthropic_api_key', '');
        $this->custom_ai_api_key = $this->get_option('custom_ai_api_key', '');
        // Set S3 endpoint based on region
        $this->s3_endpoint = $this->get_s3_endpoint($this->region);
    }
    
    private function get_option($key, $default = '') {
        if (!isset($this->options[$key])) {
            return $default;
        }

        $value = $this->options[$key];

        if (in_array($key, $this->sensitive_fields, true)) {
            return $this->decrypt_sensitive_value($value);
        }

        return $value;
    }

    private function get_runtime_options() {
        $runtime = $this->options;
        foreach ($this->sensitive_fields as $field) {
            if (isset($runtime[$field])) {
                $runtime[$field] = $this->get_option($field, '');
            }
        }

        return $runtime;
    }
    
    private function init_hooks() {
        // Admin hooks
        add_action('admin_menu', array($this, 'add_admin_menus'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // AJAX hooks
        add_action('wp_ajax_setup_aws_resources', array($this, 'ajax_setup_aws_resources'));
        add_action('wp_ajax_queue_s3_upload', array($this, 'ajax_queue_s3_upload'));
        add_action('wp_ajax_queue_s3_download', array($this, 'ajax_queue_s3_download'));
        add_action('wp_ajax_optimize_media', array($this, 'ajax_optimize_media'));
        add_action('wp_ajax_bulk_optimize_media', array($this, 'ajax_bulk_optimize_media'));
        add_action('wp_ajax_get_operation_status', array($this, 'ajax_get_operation_status'));
        add_action('wp_ajax_get_logs', array($this, 'ajax_get_logs'));
        add_action('wp_ajax_bulk_s3_upload', array($this, 'ajax_bulk_s3_upload'));
        add_action('wp_ajax_bulk_s3_download', array($this, 'ajax_bulk_s3_download'));
        add_action('wp_ajax_manual_upload_all', array($this, 'ajax_manual_upload_all'));
        add_action('wp_ajax_retry_failed_operations', array($this, 'ajax_retry_failed_operations'));
        add_action('wp_ajax_generate_ai_alt_tag', array($this, 'ajax_generate_ai_alt_tag'));
        add_action('wp_ajax_bulk_generate_ai_alt_tags', array($this, 'ajax_bulk_generate_ai_alt_tags'));
        
        // Test connection AJAX handlers
        add_action('wp_ajax_test_s3_connection', array($this, 'ajax_test_s3_connection'));
        add_action('wp_ajax_test_cloudfront_connection', array($this, 'ajax_test_cloudfront_connection'));
        add_action('wp_ajax_test_sqs_connection', array($this, 'ajax_test_sqs_connection'));
        add_action('wp_ajax_get_log_stats', array($this, 'ajax_get_log_stats'));

        // Media library hooks
        add_filter('attachment_fields_to_edit', array($this, 'add_media_fields'), 10, 2);
        add_filter('bulk_actions-upload', array($this, 'register_media_bulk_actions'));
        add_filter('handle_bulk_actions-upload', array($this, 'handle_media_bulk_actions'), 10, 3);
        
        // Cron hooks for SQS processing
        add_action('enhanced_s3_process_queue', array($this, 'process_sqs_queue'));
        add_action('wp_generate_attachment_metadata', array($this, 'auto_upload_after_processing'), 10, 2);
        add_action('wp_ajax_download_all_s3_files', array($this, 'ajax_download_all_s3_files'));
        add_action('admin_notices', array($this, 'deletion_protection_notice'));
        add_action('admin_notices', array($this, 'render_media_bulk_notice'));
        if ($this->auto_upload_new_files) {
            add_action('admin_notices', array($this, 'auto_upload_admin_notice'));
        }
        $this->register_post_bulk_hooks();
        // Schedule cron if not already scheduled
        if (!wp_next_scheduled('enhanced_s3_process_queue')) {
            wp_schedule_event(time(), 'every_30_seconds', 'enhanced_s3_process_queue');
        }
        
        // URL replacement hooks
        add_filter('wp_get_attachment_url', array($this, 'get_attachment_url'), 10, 2);
        add_filter('wp_calculate_image_srcset', array($this, 'update_image_srcset'), 10, 5);
    }

    public function auto_upload_admin_notice() {
        if ($this->auto_upload_new_files && current_user_can('upload_files')) {
            echo '<div class="notice notice-info">
                <p><strong>FeatherLift Media:</strong> Auto-upload is enabled. New media files will be automatically uploaded to S3.</p>
            </div>';
        }
    }
    
    public function ajax_manual_upload_all() {
        check_ajax_referer('enhanced_s3_nonce', 'nonce');
        
        global $wpdb;
        $attachments = $wpdb->get_results("
            SELECT p.ID FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'enhanced_s3_key'
            WHERE p.post_type = 'attachment' AND pm.meta_value IS NULL
        ");
        
        if (!empty($attachments)) {
            $first = reset($attachments);
            if (!$this->ensure_bucket_available($first->ID)) {
                wp_send_json_error('Unable to create S3 bucket automatically. Check AWS permissions.');
            }
        }

        $count = 0;
        foreach ($attachments as $attachment) {
            try {
                $this->queue_manager->queue_upload($attachment->ID, array(
                    'source' => 'manual-upload-all',
                    'initiator' => get_current_user_id()
                ));
                $count++;
            } catch (Exception $e) {
                // Continue with next file
            }
        }
        
        wp_send_json_success(array('count' => $count));
    }

    public function ajax_retry_failed_operations() {
        check_ajax_referer('enhanced_s3_nonce', 'nonce');
        
        $count = $this->queue_manager->retry_failed_operations();
        wp_send_json_success(array('count' => $count));
    }

    public function ajax_generate_ai_alt_tag() {
        check_ajax_referer('enhanced_s3_nonce', 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error('Insufficient permissions');
        }

        $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
        $overwrite = isset($_POST['overwrite']) && $_POST['overwrite'] === 'true';

        $result = $this->generate_ai_alt_text($attachment_id, $overwrite, array(
            'source' => 'media-single',
            'initiator' => get_current_user_id()
        ));

        if (!empty($result['success'])) {
            wp_send_json_success($result);
        }

        wp_send_json_error($result['error'] ?? 'Unable to generate alt text');
    }

    public function ajax_bulk_generate_ai_alt_tags() {
        check_ajax_referer('enhanced_s3_nonce', 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error('Insufficient permissions');
        }

        $attachment_ids = isset($_POST['attachment_ids']) ? array_map('absint', (array) $_POST['attachment_ids']) : array();
        $overwrite = isset($_POST['overwrite']) && $_POST['overwrite'] === 'true';

        if (empty($attachment_ids)) {
            wp_send_json_error('No media selected');
        }

        $summary = array(
            'success' => 0,
            'skipped' => 0,
            'failed'  => 0,
            'errors'  => array()
        );
        $batch_key = uniqid('media-bulk-', true);

        foreach ($attachment_ids as $attachment_id) {
            $result = $this->generate_ai_alt_text($attachment_id, $overwrite, array(
                'source' => 'media-bulk-ajax',
                'initiator' => get_current_user_id(),
                'batch' => $batch_key
            ));
            if (!empty($result['success'])) {
                $summary['success']++;
            } elseif (!empty($result['skipped'])) {
                $summary['skipped']++;
            } else {
                $summary['failed']++;
                if (!empty($result['error'])) {
                    $summary['errors'][] = 'ID ' . $attachment_id . ': ' . $result['error'];
                }
            }
        }

        wp_send_json_success($summary);
    }

    /**
     * Auto-upload after WordPress finishes processing thumbnails
     */
    public function auto_upload_after_processing($metadata, $attachment_id) {
        if (!$this->auto_upload_new_files || !$this->is_configured()) {
            return $metadata;
        }
        
        // This ensures thumbnails are generated before S3 upload
        // The queue will process this after thumbnail generation is complete
        $this->auto_upload_new_attachment($attachment_id);
        
        return $metadata;
    }

    /**
     * Create database tables
     */
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$this->logs_table} (
            id int(11) NOT NULL AUTO_INCREMENT,
            attachment_id int(11) NOT NULL,
            operation_type varchar(32) NOT NULL DEFAULT 'upload',
            status varchar(20) NOT NULL DEFAULT 'requested',
            file_name varchar(255) NOT NULL,
            file_size bigint(20) DEFAULT NULL,
            s3_key varchar(500) DEFAULT NULL,
            error_message text DEFAULT NULL,
            job_meta longtext DEFAULT NULL,
            started_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY attachment_id (attachment_id),
            KEY status (status),
            KEY operation_type (operation_type)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        update_option('enhanced_s3_db_version', $this->db_version);
    }

    private function maybe_upgrade_database() {
        $installed = get_option('enhanced_s3_db_version');
        if ($installed !== $this->db_version) {
            $this->create_tables();
        }
    }

    public function after_settings_updated($old_value, $value, $option_name) {
        if ($this->suppress_settings_reactions) {
            return;
        }

        $this->options = $value;
        $this->load_options();

        if (!$this->is_configured()) {
            return;
        }

        $needs_bucket = empty($this->bucket_name);
        $needs_queue = empty($this->sqs_queue_url);
        $needs_cdn = $this->use_cloudfront && empty($this->cloudfront_domain);

        if (!$needs_bucket && !$needs_queue && !$needs_cdn) {
            return;
        }

        try {
            $result = $this->provision_aws_stack();
            $this->store_setup_notice($result['message']);
        } catch (Exception $e) {
            $this->store_setup_notice('Automatic AWS provisioning failed: ' . $e->getMessage(), 'error');
        }
    }

    private function store_setup_notice($message, $type = 'updated') {
        $key = 'enhanced_s3_setup_notice_' . get_current_user_id();
        set_transient($key, array(
            'message' => $message,
            'type' => $type
        ), MINUTE_IN_SECONDS);
    }

    public function render_setup_notice() {
        $key = 'enhanced_s3_setup_notice_' . get_current_user_id();
        $notice = get_transient($key);
        if (!$notice) {
            return;
        }
        delete_transient($key);
        $class = ($notice['type'] === 'error') ? 'notice notice-error' : 'notice notice-success';
        printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($notice['message']));
    }

    private function provision_aws_stack() {
        if (!$this->aws_sdk) {
            $this->init_aws_components();
        }

        if (!$this->aws_sdk) {
            throw new Exception('AWS SDK not initialized. Please verify credentials.');
        }

        $updates = array();
        $bucket_name = $this->bucket_name;

        if (empty($bucket_name)) {
            $custom_bucket = trim($this->get_option('bucket_name'));
            if (!empty($custom_bucket)) {
                $bucket_name = strtolower(preg_replace('/[^a-z0-9-]/', '', $custom_bucket));
                if (strlen($bucket_name) < 3 || strlen($bucket_name) > 63) {
                    throw new Exception('Bucket name must be between 3 and 63 characters.');
                }
            } else {
                $site_name = sanitize_title(get_bloginfo('name'));
                $unique_id = substr(md5(get_site_url()), 0, 8);
                $bucket_name = substr(strtolower(preg_replace('/[^a-z0-9-]/', '', $site_name)), 0, 40);
                $bucket_name = trim($bucket_name, '-');
                if (empty($bucket_name)) {
                    $bucket_name = 'wp-media';
                }
                $bucket_name .= '-' . $unique_id;
            }

            $bucket_result = $this->aws_sdk->create_s3_bucket($bucket_name, array(
                'preserve_permissions' => $this->preserve_bucket_permissions
            ));

            if (empty($bucket_result['success'])) {
                throw new Exception('Failed to create S3 bucket: ' . ($bucket_result['error'] ?? 'unknown error'));
            }

            $updates['bucket_name'] = $bucket_result['bucket_name'] ?? $bucket_name;
        }

        if (empty($this->sqs_queue_url)) {
            $site_name = sanitize_title(get_bloginfo('name'));
            $unique_id = substr(md5(get_site_url()), 0, 8);
            $queue_result = $this->aws_sdk->create_sqs_queue($site_name . '-' . $unique_id);
            if (empty($queue_result['success'])) {
                throw new Exception('Failed to create SQS queue: ' . ($queue_result['error'] ?? 'unknown error'));
            }
            $queue_url = $queue_result['queue_url'] ?? '';
            if (isset($queue_result['CreateQueueResult']['QueueUrl'])) {
                $queue_url = $queue_result['CreateQueueResult']['QueueUrl'];
            }
            if (empty($queue_url)) {
                throw new Exception('Unable to determine SQS queue URL.');
            }
            $updates['sqs_queue_url'] = $queue_url;
        }

        if ($this->use_cloudfront && empty($this->cloudfront_domain)) {
            $target_bucket = !empty($updates['bucket_name']) ? $updates['bucket_name'] : $this->bucket_name;
            $cloudfront_result = $this->aws_sdk->create_cloudfront_distribution($target_bucket);
            if (empty($cloudfront_result['success'])) {
                throw new Exception('Failed to create CloudFront distribution: ' . ($cloudfront_result['error'] ?? 'unknown error'));
            }
            if (!empty($cloudfront_result['domain'])) {
                $updates['cloudfront_domain'] = $cloudfront_result['domain'];
                $updates['cloudfront_distribution_id'] = $cloudfront_result['distribution_id'] ?? '';
            }
        }

        if (empty($this->options['s3_prefix'])) {
            $updates['s3_prefix'] = 'wp-content/uploads/';
        }

        if (!empty($updates)) {
            $this->persist_settings($updates);
        }

        return array(
            'bucket_name' => $this->bucket_name,
            'queue_url' => $this->sqs_queue_url,
            'cloudfront_domain' => $this->cloudfront_domain,
            'cloudfront_distribution_id' => $this->cloudfront_distribution_id,
            'message' => 'AWS resources configured successfully'
        );
    }

    private function persist_settings($updates) {
        $this->options = array_merge($this->options, $updates);
        $this->suppress_settings_reactions = true;
        update_option('enhanced_s3_settings', $this->options);
        $this->suppress_settings_reactions = false;
        $this->load_options();
        if ($this->aws_sdk) {
            $this->queue_manager = new Enhanced_S3_Queue_Manager($this->aws_sdk, $this->get_runtime_options());
        }
    }
    
    /**
     * Add admin menus
     */
    public function add_admin_menus() {
        add_options_page(
            'FeatherLift Media Settings',
            'FeatherLift Media',
            'manage_options',
            'enhanced-s3-settings',
            array($this, 'settings_page')
        );
        
        add_media_page(
            'S3 Operation Logs',
            'S3 Logs',
            'manage_options',
            'enhanced-s3-logs',
            array($this, 'logs_page')
        );
        add_media_page(
            'S3 Bulk Operations',
            'S3 Bulk Upload',
            'manage_options',
            'enhanced-s3-bulk',
            array($this, 'bulk_page')
        );

    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('enhanced_s3_settings_group', 'enhanced_s3_settings', array($this, 'sanitize_settings'));

        add_settings_section(
            'enhanced_s3_intent_section',
            'Media Workflow Intent',
            array($this, 'intent_section_callback'),
            'enhanced-s3-settings'
        );

        add_settings_field(
            'optimize_media',
            'Optimize media before delivery',
            array($this, 'optimize_media_field'),
            'enhanced-s3-settings',
            'enhanced_s3_intent_section'
        );

        add_settings_field(
            'offload_media',
            'Host media on external storage/CDN',
            array($this, 'offload_media_field'),
            'enhanced-s3-settings',
            'enhanced_s3_intent_section'
        );

        add_settings_section(
            'enhanced_s3_optimize_section',
            'Optimize & Resize',
            array($this, 'optimize_section_callback'),
            'enhanced-s3-settings'
        );

        $optimize_fields = array(
            'auto_resize_images'   => 'Enable resizing rules',
            'resize_max_width'     => 'Max width (px)',
            'resize_max_height'    => 'Max height (px)',
            'compress_images'      => 'Enable compression service',
            'compression_service'  => 'Preferred optimizer',
            'compression_quality'  => 'Native quality',
            'tinypng_api_key'      => 'TinyPNG API key'
        );

        foreach ($optimize_fields as $field => $label) {
            add_settings_field(
                $field,
                $label,
                array($this, $field . '_field'),
                'enhanced-s3-settings',
                'enhanced_s3_optimize_section'
            );
        }

        add_settings_section(
            'enhanced_s3_offload_section',
            'External Storage & CDN',
            array($this, 'offload_section_callback'),
            'enhanced-s3-settings'
        );

        $offload_fields = array(
            'access_key'                => 'AWS Access Key',
            'secret_key'                => 'AWS Secret Key',
            'region'                    => 'AWS Region',
            'bucket_name'               => 'Bucket name',
            'bucket_autoname_strategy'  => 'Bucket naming strategy',
            's3_prefix'                 => 'Upload prefix',
            'preserve_bucket_permissions' => 'Bucket permission strategy',
            'use_cloudfront'            => 'Enable CloudFront CDN',
            'cloudfront_domain'         => 'CloudFront domain (manual)',
            'cloudfront_distribution_id'=> 'CloudFront distribution ID',
            'upload_thumbnails'         => 'Upload generated thumbnail sizes',
            'auto_delete_local'         => 'Remove local copies after upload'
        );

        foreach ($offload_fields as $field => $label) {
            add_settings_field(
                $field,
                $label,
                array($this, $field . '_field'),
                'enhanced-s3-settings',
                'enhanced_s3_offload_section'
            );
        }

        add_settings_section(
            'enhanced_s3_automation_section',
            'Automation & Workflow',
            array($this, 'automation_section_callback'),
            'enhanced-s3-settings'
        );

        add_settings_field(
            'auto_upload_new_files',
            'Automatically process future uploads',
            array($this, 'auto_upload_new_files_field'),
            'enhanced-s3-settings',
            'enhanced_s3_automation_section'
        );

        add_settings_field(
            'auto_upload_file_types',
            'File types to include',
            array($this, 'auto_upload_file_types_field'),
            'enhanced-s3-settings',
            'enhanced_s3_automation_section'
        );

        if ($this->ai_features_available) {
            add_settings_section(
                'enhanced_s3_ai_section',
                'AI Alt Text Automation',
                array($this, 'ai_section_callback'),
                'enhanced-s3-settings'
            );

            add_settings_field(
                'ai_alt_enabled',
                'Enable Alt Generation',
                array($this, 'ai_alt_enabled_field'),
                'enhanced-s3-settings',
                'enhanced_s3_ai_section'
            );

            add_settings_field(
                'ai_site_brief',
                'Site Context',
                array($this, 'ai_site_brief_field'),
                'enhanced-s3-settings',
                'enhanced_s3_ai_section'
            );

            add_settings_field(
                'ai_agent',
                'AI Provider',
                array($this, 'ai_agent_field'),
                'enhanced-s3-settings',
                'enhanced_s3_ai_section'
            );

            add_settings_field(
                'ai_model',
                'Preferred Model',
                array($this, 'ai_model_field'),
                'enhanced-s3-settings',
                'enhanced_s3_ai_section'
            );

            add_settings_field(
                'ai_skip_existing_alt',
                'Skip Existing Alt Text',
                array($this, 'ai_skip_existing_alt_field'),
                'enhanced-s3-settings',
                'enhanced_s3_ai_section'
            );

            add_settings_field(
                'openai_api_key',
                'OpenAI API Key',
                array($this, 'openai_api_key_field'),
                'enhanced-s3-settings',
                'enhanced_s3_ai_section'
            );

            add_settings_field(
                'anthropic_api_key',
                'Anthropic API Key',
                array($this, 'anthropic_api_key_field'),
                'enhanced-s3-settings',
                'enhanced_s3_ai_section'
            );

            add_settings_field(
                'custom_ai_endpoint',
                'Custom Endpoint URL',
                array($this, 'custom_ai_endpoint_field'),
                'enhanced-s3-settings',
                'enhanced_s3_ai_section'
            );

            add_settings_field(
                'custom_ai_api_key',
                'Custom Endpoint API Key',
                array($this, 'custom_ai_api_key_field'),
                'enhanced-s3-settings',
                'enhanced_s3_ai_section'
            );
        }
    }
    public function auto_upload_file_types_field() {
        $value = $this->get_option('auto_upload_file_types', array('image'));
        $types = array(
            'image' => 'Images',
            'video' => 'Videos', 
            'audio' => 'Audio',
            'document' => 'Documents',
            'all' => 'All File Types'
        );
        
        echo '<fieldset>';
        foreach ($types as $type => $label) {
            $checked = in_array($type, $value) ? 'checked' : '';
            echo '<label><input type="checkbox" name="enhanced_s3_settings[auto_upload_file_types][]" value="' . esc_attr($type) . '" ' . $checked . '> ' . esc_html($label) . '</label><br>';
        }
        echo '</fieldset>';
        echo '<p class="description">Select which file types to automatically upload to S3</p>';
    }
    public function auto_upload_new_files_field() {
        $value = $this->get_option('auto_upload_new_files');
        echo '<input type="checkbox" name="enhanced_s3_settings[auto_upload_new_files]" value="1" ' . checked($value, true, false) . '>';
        echo '<p class="description">Automatically push every future upload to S3. Uncheck to keep new files local.</p>';
    }
    public function auto_upload_new_attachment($attachment_id) {

        error_log('Auto upload called for: ' . $attachment_id);
        error_log('Auto upload enabled: ' . print_r($this->auto_upload_new_files, true));
        error_log('Plugin configured: ' . ($this->is_configured() ? 'yes' : 'no'));

        if (!$this->auto_upload_new_files || !$this->is_configured()) {
            return;
        }
        
        // Skip if already on S3
        $existing_s3_key = get_post_meta($attachment_id, 'enhanced_s3_key', true);
        if (!empty($existing_s3_key)) {
            return;
        }
        
        // Check if file type should be auto-uploaded
        if (!$this->should_auto_upload_file_type($attachment_id)) {
            return;
        }

        $was_on_s3 = get_post_meta($attachment_id, '_was_on_s3', true);
        if ($was_on_s3) {
            delete_post_meta($attachment_id, '_was_on_s3');
            return;
        }

        if (!$this->ensure_bucket_available($attachment_id)) {
            error_log('FeatherLift Media: Skipping auto-upload because bucket could not be created.');
            return;
        }
        
        // Queue for upload
        try {
            if ($this->queue_manager) {
                $this->queue_manager->queue_upload($attachment_id, array(
                    'source' => 'auto-upload',
                    'initiator' => get_current_user_id()
                ));

                // Process queue immediately to avoid thumbnail delays
                $this->process_sqs_queue();
                
                // Add notice for user
                add_action('admin_notices', function() use ($attachment_id) {
                    echo '<div class="notice notice-info is-dismissible">
                        <p>Media file queued for S3 upload (ID: ' . $attachment_id . ')</p>
                    </div>';
                });
            }
        } catch (Exception $e) {
            error_log('FeatherLift Media: Auto-upload failed for attachment ' . $attachment_id . ': ' . $e->getMessage());
        }
    }
    public function compression_section_callback() {
        echo '<p>Compress images before uploading to S3 to save storage and bandwidth costs.</p>';
    }

    public function compress_images_field() {
        $value = $this->get_option('compress_images');
        echo '<input type="checkbox" name="enhanced_s3_settings[compress_images]" value="1" ' . checked($value, true, false) . '>';
        echo '<p class="description">Compress images before S3 upload</p>';
    }

    public function compression_service_field() {
        $value = $this->get_option('compression_service', 'php_native');
        $services = array(
            'php_native' => 'PHP Native (GD/Imagick)',
            'tinypng' => 'TinyPNG API',
            'imageoptim' => 'ImageOptim API'
        );
        
        echo '<select name="enhanced_s3_settings[compression_service]" id="compression-service-select">';
        foreach ($services as $code => $name) {
            echo '<option value="' . esc_attr($code) . '" ' . selected($value, $code, false) . '>' . esc_html($name) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">Choose compression service</p>';
    }
    public function compression_quality_field() {
        $value = $this->get_option('compression_quality', 85);
        echo '<input type="range" name="enhanced_s3_settings[compression_quality]" value="' . esc_attr($value) . '" min="60" max="100" step="5" oninput="this.nextElementSibling.value = this.value">';
        echo '<output>' . $value . '</output>%';
        echo '<p class="description">Higher quality = larger file size (recommended: 80-90)</p>';
    }
    public function tinypng_api_key_field() {
        $has_value = $this->has_stored_secret('tinypng_api_key');
        $current_service = $this->get_option('compression_service', 'php_native');
        $active = ($current_service === 'tinypng');
        
        echo '<div id="tinypng-api-key-field" class="tinypng-api-key-panel" data-active="' . ($active ? '1' : '0') . '">';
        echo '<p class="description muted" data-tinypng-hint style="' . ($active ? 'display:none;' : '') . '">Select TinyPNG from the dropdown above to unlock this field.</p>';
        echo '<input type="password" name="enhanced_s3_settings[tinypng_api_key]" value="" class="regular-text" placeholder="' . ($has_value ? '********' : '') . '" ' . ($active ? '' : 'disabled="disabled"') . '>';
        echo '<input type="hidden" name="enhanced_s3_settings[tinypng_api_key_masked]" value="' . ($has_value ? '1' : '0') . '">';
        echo '<input type="hidden" name="enhanced_s3_settings[tinypng_api_key_clear]" value="0">';
        $description = 'Required for TinyPNG service. <a href="https://tinypng.com/developers" target="_blank">Get API key</a>';
        if ($has_value) {
            $description .= ' - <button type="button" class="button-link enhanced-s3-clear-secret" data-field="tinypng_api_key">Remove stored key</button>';
        }
        echo '<p class="description">' . $description . '</p>';
        echo '</div>';
    }

    public function resize_section_callback() {
        echo '<p>Keep originals locally but automatically downscale what gets pushed to S3.</p>';
    }

    public function auto_resize_images_field() {
        $value = $this->get_option('auto_resize_images', false);
        echo '<label><input type="checkbox" id="enhanced-s3-auto-resize" name="enhanced_s3_settings[auto_resize_images]" value="1" ' . checked($value, true, false) . '> Resize originals before S3 upload</label>';
    }

    public function resize_max_width_field() {
        $value = intval($this->get_option('resize_max_width', 2048));
        $enabled = (bool) $this->get_option('auto_resize_images', false);
        echo '<input type="number" name="enhanced_s3_settings[resize_max_width]" value="' . esc_attr($value) . '" min="0" step="100" class="small-text" ' . disabled(!$enabled, true, false) . '>';
        echo '<p class="description">Leave 0 to skip width constraint.</p>';
    }

    public function resize_max_height_field() {
        $value = intval($this->get_option('resize_max_height', 2048));
        $enabled = (bool) $this->get_option('auto_resize_images', false);
        echo '<input type="number" name="enhanced_s3_settings[resize_max_height]" value="' . esc_attr($value) . '" min="0" step="100" class="small-text" ' . disabled(!$enabled, true, false) . '>';
        echo '<p class="description">Leave 0 to skip height constraint.</p>';
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        $screens = array(
            'settings_page_enhanced-s3-settings',
            'media_page_enhanced-s3-logs',
            'media_page_enhanced-s3-bulk',
            'upload.php',
            'post.php'
        );

        if (!in_array($hook, $screens, true)) {
            return;
        }

        wp_enqueue_script(
            'enhanced-s3-admin',
            plugin_dir_url(__FILE__) . 'assets/admin.js',
            array('jquery'),
            $this->version,
            true
        );

        wp_localize_script('enhanced-s3-admin', 'enhancedS3Ajax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('enhanced_s3_nonce'),
            'strings' => array(
                'setting_up' => 'Setting up AWS resources...',
                'uploading' => 'Queuing upload...',
                'downloading' => 'Queuing download...',
                'success' => 'Operation completed successfully',
                'error' => 'Operation failed',
                'alt_generating' => 'Generating alt tag...',
                'alt_success' => 'Alt text updated',
                'alt_error' => 'Unable to generate alt text',
                'alt_skip' => 'Alt text already exists',
                'optimize_queueing' => 'Optimizing media...',
                'optimize_success' => 'Optimization complete',
                'optimize_error' => 'Unable to optimize media'
            ),
            'workflows' => array(
                'optimize_enabled' => (bool) $this->optimize_media,
                'offload_enabled' => (bool) $this->offload_media,
                'cdn_enabled' => (bool) ($this->use_cloudfront && !empty($this->cloudfront_domain)),
                'aws_configured' => (bool) $this->is_configured()
            ),
            'ai' => array(
                'enabled' => (bool) $this->ai_alt_enabled,
                'agent' => $this->ai_agent,
                'models' => $this->get_ai_model_map()
            )
        ));

        wp_enqueue_style(
            'enhanced-s3-admin',
            plugin_dir_url(__FILE__) . 'assets/admin.css',
            array(),
            $this->version
        );
    }

    // Replace the entire existing method with the new comprehensive one
    public function ajax_reset_aws_resources() {
        check_ajax_referer('enhanced_s3_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $download_files = isset($_POST['download_files']) && $_POST['download_files'] === 'true';
        $delete_aws_resources = isset($_POST['delete_aws_resources']) && $_POST['delete_aws_resources'] === 'true';
        
        try {
            $results = array();
            
            // Download files first if requested
            if ($download_files) {
                $results['files'] = $this->process_s3_files(true, false);
            }
            
            // Delete AWS resources if requested
            if ($delete_aws_resources) {
                $results['aws'] = $this->delete_aws_resources();
            }
            
            // Reset configuration
            $this->reset_plugin_configuration();
            
            // Build success message
            $message = 'AWS configuration reset successfully.';
            if ($download_files && isset($results['files'])) {
                $message .= " Downloaded {$results['files']['downloaded']} files.";
            }
            if ($delete_aws_resources && isset($results['aws'])) {
                $aws_deleted = array_filter(array(
                    $results['aws']['bucket_deleted'] ? 'S3 bucket' : null,
                    $results['aws']['queue_deleted'] ? 'SQS queue' : null,
                    $results['aws']['cloudfront_deleted'] ? 'CloudFront distribution' : null
                ));
                if (!empty($aws_deleted)) {
                    $message .= " Deleted: " . implode(', ', $aws_deleted) . ".";
                }
                if (!empty($results['aws']['errors'])) {
                    $message .= " Errors: " . implode('; ', $results['aws']['errors']);
                }
            }
            
            wp_send_json_success($message);
            
        } catch (Exception $e) {
            wp_send_json_error('Reset failed: ' . $e->getMessage());
        }
    }

    /**
     * Send restore notification emails
     */
    private function send_restore_notification($type, $data = array()) {
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        $site_url = get_site_url();
        
        switch ($type) {
            case 'restore_started':
                $subject = "[{$site_name}] S3 File Restore Started";
                $message = $this->get_restore_started_email($data, $site_name, $site_url);
                break;
                
            case 'restore_completed':
                $subject = "[{$site_name}] S3 File Restore Completed";
                $message = $this->get_restore_completed_email($data, $site_name, $site_url);
                break;
                
            case 'restore_failed':
                $subject = "[{$site_name}] S3 File Restore Failed";
                $message = $this->get_restore_failed_email($data, $site_name, $site_url);
                break;
                
            default:
                return false;
        }
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <noreply@' . parse_url($site_url, PHP_URL_HOST) . '>'
        );
        
        return wp_mail($admin_email, $subject, $message, $headers);
    }

    private function get_restore_started_email($data, $site_name, $site_url) {
        $file_count = $data['file_count'] ?? 0;
        $estimated_time = $data['estimated_time'] ?? 'several minutes';
        
        return "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color: #0073aa;'>S3 File Restore Started</h2>
                
                <p>Hi there,</p>
                
                <p>We've started downloading your media files from Amazon S3 back to your WordPress site.</p>
                
                <div style='background: #f0f8ff; padding: 15px; border-left: 4px solid #0073aa; margin: 20px 0;'>
                    <h3 style='margin-top: 0;'>Restore Details:</h3>
                    <ul>
                        <li><strong>Site:</strong> {$site_name}</li>
                        <li><strong>Files to download:</strong> {$file_count}</li>
                        <li><strong>Estimated time:</strong> {$estimated_time}</li>
                        <li><strong>Started:</strong> " . current_time('F j, Y g:i A') . "</li>
                    </ul>
                </div>
                
                <p>The download process is running in the background. You'll receive another email when it's complete.</p>
                
                <p><strong>What's happening:</strong></p>
                <ul>
                    <li>Files are being downloaded from S3 to your local server</li>
                    <li>Your website will continue to work normally during this process</li>
                    <li>Original files remain safely on S3 until you manually delete them</li>
                </ul>
                
                <p>You can monitor progress in your WordPress admin: <a href='{$site_url}/wp-admin/upload.php?page=enhanced-s3-logs'>View S3 Logs</a></p>
                
                <p>Best regards,<br>FeatherLift Media</p>
            </div>
        </body>
        </html>";
    }

    private function get_restore_completed_email($data, $site_name, $site_url) {
        $downloaded = $data['downloaded'] ?? 0;
        $failed = $data['failed'] ?? 0;
        $duration = $data['duration'] ?? 'unknown';
        $total_size = $data['total_size'] ?? 'unknown';
        
        $status_color = $failed > 0 ? '#f39c12' : '#46b450';
        $status_text = $failed > 0 ? 'Completed with some issues' : 'Successfully completed';
        
        return "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color: {$status_color};'>S3 File Restore {$status_text}</h2>
                
                <p>Great news! Your S3 file restore has finished.</p>
                
                <div style='background: #f0f8ff; padding: 15px; border-left: 4px solid {$status_color}; margin: 20px 0;'>
                    <h3 style='margin-top: 0;'>Restore Summary:</h3>
                    <ul>
                        <li><strong>Site:</strong> {$site_name}</li>
                        <li><strong>Files downloaded:</strong> {$downloaded}</li>
                        " . ($failed > 0 ? "<li><strong>Files failed:</strong> {$failed}</li>" : "") . "
                        <li><strong>Total data:</strong> {$total_size}</li>
                        <li><strong>Duration:</strong> {$duration}</li>
                        <li><strong>Completed:</strong> " . current_time('F j, Y g:i A') . "</li>
                    </ul>
                </div>
                
                " . ($failed > 0 ? "
                <div style='background: #fff3cd; padding: 15px; border-left: 4px solid #f39c12; margin: 20px 0;'>
                    <p><strong>Note:</strong> {$failed} files failed to download. Check the <a href='{$site_url}/wp-admin/upload.php?page=enhanced-s3-logs'>S3 Logs</a> for details.</p>
                </div>
                " : "") . "
                
                <p><strong>What's next:</strong></p>
                <ul>
                    <li>Your media files are now stored locally on your WordPress server</li>
                    <li>Your website will load images from local storage instead of S3</li>
                    <li>S3 files remain untouched as a backup copy</li>
                    <li>You can now safely disable the S3 plugin if desired</li>
                </ul>
                
                <p><strong>Important:</strong> If you want to completely stop using AWS, you'll need to manually delete your S3 bucket via the AWS console.</p>
                
                <p>View detailed logs: <a href='{$site_url}/wp-admin/upload.php?page=enhanced-s3-logs'>S3 Operation Logs</a></p>
                
                <p>Best regards,<br>FeatherLift Media</p>
            </div>
        </body>
        </html>";
    }

    private function get_restore_failed_email($data, $site_name, $site_url) {
        $error = $data['error'] ?? 'Unknown error occurred';
        $downloaded = $data['downloaded'] ?? 0;
        
        return "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color: #dc3232;'>S3 File Restore Failed</h2>
                
                <p>We encountered an issue while downloading your files from S3.</p>
                
                <div style='background: #fff2f2; padding: 15px; border-left: 4px solid #dc3232; margin: 20px 0;'>
                    <h3 style='margin-top: 0;'>Error Details:</h3>
                    <ul>
                        <li><strong>Site:</strong> {$site_name}</li>
                        <li><strong>Files downloaded before error:</strong> {$downloaded}</li>
                        <li><strong>Error:</strong> {$error}</li>
                        <li><strong>Time:</strong> " . current_time('F j, Y g:i A') . "</li>
                    </ul>
                </div>
                
                <p><strong>What to do:</strong></p>
                <ul>
                    <li>Check the <a href='{$site_url}/wp-admin/upload.php?page=enhanced-s3-logs'>S3 Logs</a> for more details</li>
                    <li>Try the restore process again</li>
                    <li>Contact support if the issue persists</li>
                </ul>
                
                <p><strong>Your data is safe:</strong> All files remain on S3 and your website continues to work normally.</p>
                
                <p>View logs: <a href='{$site_url}/wp-admin/upload.php?page=enhanced-s3-logs'>S3 Operation Logs</a></p>
                
                <p>Best regards,<br>FeatherLift Media</p>
            </div>
        </body>
        </html>";
    }

    /**
     * Render the FeatherLite scan table used across admin pages
     */
    private function render_media_scan_box($context = '') {
        $rows = $this->get_media_scan_rows();
        $context = sanitize_key($context);
        echo '<div class="featherlite-scan" data-context="' . esc_attr($context) . '">';
        echo '<div class="featherlite-scan-heading">';
        echo '<h2>FeatherLite Optimization Box</h2>';
        echo '<p>We scan your latest uploads so you can batch optimize or retry problem files without leaving this page.</p>';
        echo '</div>';

        if (empty($rows)) {
            echo '<div class="featherlite-empty-state">'
                . '<p>No recent media uploads found yet. Once you add new files they will appear here for quick optimization.</p>'
                . '</div>';
            echo '</div>';
            return;
        }

        echo '<div class="featherlite-scan-actions">';
        echo '<label class="featherlite-select-all"'
            . '<input type="checkbox" class="featherlite-select-all-toggle"> Select all pending'
            . '</label>';
        if ($this->optimize_media) {
            echo '<button type="button" class="button featherlite-optimize-selected is-hidden">Optimize Selected</button>';
        }
        if ($this->offload_media) {
            $upload_label = $this->optimize_media ? 'Upload Selected to S3' : 'Upload Selected';
            $disabled = $this->is_configured() ? '' : 'disabled="disabled"';
            $upload_classes = 'button button-primary featherlite-upload-selected is-hidden';
            if (!$this->is_configured()) {
                $upload_classes .= ' is-disabled';
            }
            echo '<button type="button" class="' . $upload_classes . '" ' . $disabled . '>' . esc_html($upload_label) . '</button>';
            if (!$this->is_configured()) {
                echo '<p class="description featherlite-hint">' . esc_html__('Configure AWS credentials to enable uploads.', 'enhanced-s3') . '</p>';
            }
        }
        echo '</div>';

        echo '<div class="featherlite-scan-table-wrapper">';
        echo '<table class="wp-list-table widefat fixed striped featherlite-scan-table">';
        echo '<thead><tr>';
        echo '<th class="column-cb"><span class="screen-reader-text">Select file</span></th>';
        echo '<th>Preview</th>';
        echo '<th>Media</th>';
        echo '<th>Status</th>';
        echo '<th class="column-actions">Actions</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($rows as $row) {
            $disabled = $row['is_offloaded'] ? 'disabled="disabled"' : '';
            $optimize_attr = $row['can_optimize'] ? '1' : '0';
            $upload_attr = $row['can_upload'] ? '1' : '0';
            echo '<tr data-attachment-id="' . esc_attr($row['id']) . '">';
            echo '<td class="column-cb"'
                . '<input type="checkbox" class="featherlite-row-select" value="' . esc_attr($row['id']) . '" data-status="' . esc_attr($row['status_slug']) . '" data-can-optimize="' . esc_attr($optimize_attr) . '" data-can-upload="' . esc_attr($upload_attr) . '" ' . $disabled . '>'
                . '</td>';
            echo '<td class="column-thumb">' . wp_kses_post($row['thumbnail']) . '</td>';
            echo '<td>'
                . '<span class="featherlite-media-title">' . esc_html($row['title']) . '</span>'
                . '<span class="featherlite-media-meta">' . esc_html($row['subtitle']) . '</span>'
                . '</td>';
            echo '<td>'
                . '<span class="featherlite-status badge-' . esc_attr($row['status_slug']) . '">' . esc_html($row['status_label']) . '</span>'
                . '</td>';
            echo '<td>';
            if ($row['can_optimize']) {
                echo '<button type="button" class="button button-small enhanced-s3-optimize-btn" data-attachment-id="' . esc_attr($row['id']) . '">' . esc_html__('Optimize', 'enhanced-s3') . '</button>';
            }
            if ($row['can_upload'] && !$row['is_offloaded']) {
                echo '<button type="button" class="button button-small enhanced-s3-upload-btn" data-attachment-id="' . esc_attr($row['id']) . '">' . esc_html__('Upload to S3', 'enhanced-s3') . '</button>';
            } elseif ($this->offload_media && !$this->is_configured()) {
                echo '<span class="featherlite-hint">' . esc_html__('Add AWS keys to enable upload', 'enhanced-s3') . '</span>';
            } elseif ($row['is_offloaded']) {
                echo '<span class="featherlite-hint">' . esc_html__('Already on S3', 'enhanced-s3') . '</span>';
            }
            echo '<div class="featherlite-row-status" id="opt-status-' . esc_attr($row['id']) . '"></div>';
            echo '<div class="featherlite-row-status" id="status-' . esc_attr($row['id']) . '"></div>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
        echo '<div class="featherlite-bulk-status" aria-live="polite"></div>';
        echo '</div>';
    }

    /**
     * Collect recent media rows for the scan box
     */
    private function get_media_scan_rows($limit = 8) {
        $limit = absint(apply_filters('featherlite_media_scan_limit', $limit));
        if ($limit <= 0) {
            $limit = 8;
        }

        $query = new WP_Query(array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
            'fields'         => 'all'
        ));

        if (!$query->have_posts()) {
            wp_reset_postdata();
            return array();
        }

        $attachments = $query->posts;
        wp_reset_postdata();

        $attachment_ids = wp_list_pluck($attachments, 'ID');
        $log_status_map = array();

        if (!empty($attachment_ids) && $this->logs_table) {
            global $wpdb;
            $placeholders = implode(',', array_fill(0, count($attachment_ids), '%d'));
            $sql = "SELECT attachment_id, status FROM {$this->logs_table} WHERE attachment_id IN ($placeholders) ORDER BY created_at DESC";
            $log_rows = $wpdb->get_results($wpdb->prepare($sql, $attachment_ids));
            if ($log_rows) {
                foreach ($log_rows as $row) {
                    if (!isset($log_status_map[$row->attachment_id])) {
                        $log_status_map[$row->attachment_id] = $row->status;
                    }
                }
            }
        }

        $rows = array();

        foreach ($attachments as $attachment) {
            $attachment_id = $attachment->ID;
            $title = get_the_title($attachment_id);
            if ($title === '') {
                $file_path = get_attached_file($attachment_id);
                $title = $file_path ? basename($file_path) : 'Untitled Media';
            }

            $thumb = wp_get_attachment_image($attachment_id, array(64, 64), false, array(
                'class' => 'featherlite-thumb',
                'loading' => 'lazy'
            ));
            if (!$thumb) {
                $thumb = '<span class="featherlite-thumb placeholder">' . esc_html__('No preview', 'enhanced-s3') . '</span>';
            }

            $s3_key = get_post_meta($attachment_id, 'enhanced_s3_key', true);
            $log_status = isset($log_status_map[$attachment_id]) ? $log_status_map[$attachment_id] : '';
            $local_optimized = get_post_meta($attachment_id, 'enhanced_s3_local_optimized', true);
            $status_slug = 'pending';
            $status_label = 'Pending';
            $is_offloaded = false;

            if (!empty($s3_key) || $log_status === 'completed') {
                $status_slug = 'offloaded';
                $status_label = 'Offloaded to S3';
                $is_offloaded = true;
            } elseif ($log_status === 'failed') {
                $status_slug = 'failed';
                $status_label = 'Failed';
            } elseif (!empty($local_optimized)) {
                $status_slug = 'optimized-local';
                $status_label = 'Optimized (Local)';
            }

            $mime_type = $attachment->post_mime_type ?: 'image/jpeg';
            $is_image = strpos($mime_type, 'image/') === 0;

            $rows[] = array(
                'id' => $attachment_id,
                'title' => $title,
                'subtitle' => $mime_type,
                'thumbnail' => $thumb,
                'status_label' => $status_label,
                'status_slug' => $status_slug,
                'is_offloaded' => $is_offloaded,
                'can_optimize' => ($this->optimize_media && $is_image),
                'can_upload' => ($this->offload_media && $this->is_configured() && !$is_offloaded),
                'is_image' => $is_image
            );
        }

        return $rows;
    }

    /**
     * Settings page
     */
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>FeatherLift Media Settings</h1>
            <?php $this->render_media_scan_box('settings'); ?>
            
            <form method="post" action="options.php" id="enhanced-s3-settings-form">
                <?php
                settings_fields('enhanced_s3_settings_group');
                global $wp_settings_sections, $wp_settings_fields;
                $sections = isset($wp_settings_sections['enhanced-s3-settings']) ? $wp_settings_sections['enhanced-s3-settings'] : array();
                echo '<div class="enhanced-s3-settings-grid">';
                if (!$this->intent_committed) {
                    echo '<div class="notice notice-info intent-onboarding"><p>Select at least one option under "What should this plugin do?" and save. Optimization and offload settings will unlock after you confirm your intent.</p></div>';
                }
                foreach ($sections as $section_id => $section) {
                    if ($section_id === 'enhanced_s3_ai_section') {
                        continue;
                    }
                    if ($section_id === 'enhanced_s3_optimize_section' && (!$this->intent_committed || !$this->optimize_media)) {
                        continue;
                    }
                    if ($section_id === 'enhanced_s3_offload_section' && (!$this->intent_committed || !$this->offload_media)) {
                        continue;
                    }

                    echo '<div class="enhanced-s3-card">';
                    if (!empty($section['title'])) {
                        echo '<h2>' . esc_html($section['title']) . '</h2>';
                    }
                    if (!empty($section['callback'])) {
                        call_user_func($section['callback'], $section);
                    }
                    if (isset($wp_settings_fields['enhanced-s3-settings'][$section_id])) {
                        echo '<table class="form-table">';
                        do_settings_fields('enhanced-s3-settings', $section_id);
                        echo '</table>';
                    }
                    echo '</div>';
                }
                echo '</div>';
                ?>
                
                <?php submit_button(); ?>
            </form>
            
            <!-- AWS Setup Section - Always Show -->
            <div class="aws-setup-section">
                <h3>AWS Resource Management</h3>
                
                <?php if ($this->is_configured()): ?>
                    <?php 
                    $is_setup = !empty($this->bucket_name) && !empty($this->sqs_queue_url);
                    ?>
                    
                    <?php if ($is_setup): ?>
                        <p style="color: #46b450;"><strong>✓ AWS resources are configured and ready.</strong></p>
                        <p>Bucket: <code><?php echo esc_html($this->bucket_name); ?></code><br>
                        Queue: <code><?php echo esc_html(basename($this->sqs_queue_url)); ?></code>
                        <?php if (!empty($this->cloudfront_domain)): ?>
                            <br>CloudFront: <code><?php echo esc_html($this->cloudfront_domain); ?></code>
                        <?php endif; ?>
                        </p>
                        
                        <div class="reset-options" style="background: #fff2cc; padding: 15px; border-left: 4px solid #ffb900; margin: 15px 0;">
                            <h4>File Recovery Options</h4>
                            <p>If you want to stop using AWS S3, you can download all your files back to local storage.</p>
                            
                            <button type="button" id="download-all-files" class="button button-primary">
                                Download All S3 Files to Local Storage
                            </button>
                            
                            <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
                                <h4>Reset Configuration</h4>
                                <p>Reset plugin configuration (files remain on S3 - use download option above first if needed).</p>
                                
                                <button type="button" id="simple-reset" class="button button-secondary">
                                    Reset Plugin Configuration Only
                                </button>
                            </div>
                        </div>
                        
                    <?php else: ?>
                        <p>Your AWS credentials are configured. You can now set up AWS resources automatically.</p>
                        <button type="button" id="setup-aws-resources" class="button button-primary">
                            Setup AWS Resources
                        </button>
                    <?php endif; ?>
                    
                    <!-- Test Connection Buttons - Always Show -->
                    <div style="margin-top: 15px;">
                        <h4>Test Connections</h4>
                        <button type="button" id="test-s3-connection" class="button button-secondary">Test S3 Connection</button>
                        
                        <?php if ($this->use_cloudfront && !empty($this->cloudfront_domain)): ?>
                        <button type="button" id="test-cloudfront-connection" class="button button-secondary">Test CloudFront Connection</button>
                        <?php endif; ?>
                        
                        <?php if (!empty($this->sqs_queue_url)): ?>
                        <button type="button" id="test-sqs-connection" class="button button-secondary">Test SQS Connection</button>
                        <?php endif; ?>
                    </div>
                    
                <?php else: ?>
                    <p style="color: #d63638;"><strong>Please enter your AWS credentials above and save settings first.</strong></p>
                    <p>After saving credentials, the setup and test buttons will appear here.</p>
                <?php endif; ?>
                
                <div id="setup-status" style="margin-top: 10px;"></div>
                <div id="connection-result" style="margin-top: 10px;"></div>
            </div>
            
            <div class="current-config">
                <h3>Current Configuration</h3>
                <table class="form-table">
                    <tr>
                        <th>Plugin Status:</th>
                        <td><?php echo $this->is_configured() ? '<span style="color: green;">✓ Configured</span>' : '<span style="color: red;">✗ Not configured</span>'; ?></td>
                    </tr>
                    <tr>
                        <th>AWS Region:</th>
                        <td><?php echo esc_html($this->region ?: 'Not set'); ?></td>
                    </tr>
                    <tr>
                        <th>S3 Bucket:</th>
                        <td><?php echo esc_html($this->bucket_name ?: 'Not configured'); ?></td>
                    </tr>
                    <tr>
                        <th>SQS Queue URL:</th>
                        <td><?php echo esc_html($this->sqs_queue_url ?: 'Not configured'); ?></td>
                    </tr>
                    <tr>
                        <th>CloudFront Domain:</th>
                        <td><?php echo esc_html($this->cloudfront_domain ?: 'Not configured'); ?></td>
                    </tr>
                    <tr>
                        <th>Upload Thumbnails:</th>
                        <td><?php echo $this->upload_thumbnails ? 'Yes' : 'No'; ?></td>
                    </tr>
                    <tr>
                        <th>Auto-delete Local:</th>
                        <td><?php echo $this->auto_delete_local ? 'Yes' : 'No'; ?></td>
                    </tr>
                </table>
            </div>
            
            <!-- Instructions -->
            <div class="instructions-section" style="background: #f0f8ff; padding: 15px; border-left: 4px solid #0073aa; margin-top: 20px;">
                <h3>Quick Setup Instructions</h3>
                <ol>
                    <li><strong>Enter AWS Credentials:</strong> Add your AWS Access Key, Secret Key, and select Region above</li>
                    <li><strong>Save Settings:</strong> Click "Save Changes" button</li>
                    <li><strong>Setup Resources:</strong> Click "Setup AWS Resources" to auto-create bucket, queue, and CloudFront</li>
                    <li><strong>Test Connections:</strong> Use the test buttons to verify everything works</li>
                    <li><strong>Start Uploading:</strong> Go to Media Library and upload images to S3!</li>
                </ol>
                
                <h4>Required AWS IAM Permissions:</h4>
                <p>Your AWS user needs permissions for S3, SQS, and CloudFront. <a href="#" onclick="jQuery('#iam-permissions').toggle()">Show/Hide IAM Policy</a></p>
                
                <div id="iam-permissions" style="display: none; background: #fff; padding: 10px; margin-top: 10px; border: 1px solid #ddd;">
                    <pre style="font-size: 11px; overflow-x: auto;">{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": [
                "s3:CreateBucket", "s3:PutBucketPolicy", "s3:PutObject", 
                "s3:GetObject", "s3:DeleteObject", "s3:ListBucket"
            ],
            "Resource": ["arn:aws:s3:::ama-public-na", "arn:aws:s3:::ama-public-na/*"]
        },
        {
            "Effect": "Allow",
            "Action": [
                "sqs:CreateQueue", "sqs:SendMessage", "sqs:ReceiveMessage", 
                "sqs:DeleteMessage", "sqs:GetQueueAttributes"
            ],
            "Resource": "*"
        },
        {
            "Effect": "Allow",
            "Action": ["cloudfront:CreateDistribution", "cloudfront:GetDistribution"],
            "Resource": "*"
        }
    ]
}</pre>
                </div>
            </div>
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Test S3 Connection
            $('#test-s3-connection').on('click', function(e) {
                e.preventDefault();
                var $button = $(this);
                var $result = $('#connection-result');
                
                $button.prop('disabled', true).text('Testing S3...');
                $result.html('<p>Testing S3 connection...</p>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'test_s3_connection',
                        nonce: enhancedS3Ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $result.html('<div class="notice notice-success inline"><p>' + response.data + '</p></div>');
                        } else {
                            $result.html('<div class="notice notice-error inline"><p>' + response.data + '</p></div>');
                        }
                    },
                    error: function() {
                        $result.html('<div class="notice notice-error inline"><p>Network error occurred</p></div>');
                    },
                    complete: function() {
                        $button.prop('disabled', false).text('Test S3 Connection');
                    }
                });
            });
            
            // Test CloudFront Connection
            $('#test-cloudfront-connection').on('click', function(e) {
                e.preventDefault();
                var $button = $(this);
                var $result = $('#connection-result');
                
                $button.prop('disabled', true).text('Testing CloudFront...');
                $result.html('<p>Testing CloudFront connection...</p>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'test_cloudfront_connection',
                        nonce: enhancedS3Ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $result.html('<div class="notice notice-success inline"><p>' + response.data + '</p></div>');
                        } else {
                            $result.html('<div class="notice notice-error inline"><p>' + response.data + '</p></div>');
                        }
                    },
                    error: function() {
                        $result.html('<div class="notice notice-error inline"><p>Network error occurred</p></div>');
                    },
                    complete: function() {
                        $button.prop('disabled', false).text('Test CloudFront Connection');
                    }
                });
            });
            
            // Test SQS Connection
            $('#test-sqs-connection').on('click', function(e) {
                e.preventDefault();
                var $button = $(this);
                var $result = $('#connection-result');
                
                $button.prop('disabled', true).text('Testing SQS...');
                $result.html('<p>Testing SQS connection...</p>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'test_sqs_connection',
                        nonce: enhancedS3Ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $result.html('<div class="notice notice-success inline"><p>' + response.data + '</p></div>');
                        } else {
                            $result.html('<div class="notice notice-error inline"><p>' + response.data + '</p></div>');
                        }
                    },
                    error: function() {
                        $result.html('<div class="notice notice-error inline"><p>Network error occurred</p></div>');
                    },
                    complete: function() {
                        $button.prop('disabled', false).text('Test SQS Connection');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Logs page
     */
    public function logs_page() {
        ?>
        <div class="wrap">
            <h1>S3 Operation Logs</h1>
            <?php $this->render_media_scan_box('logs'); ?>
            <div class="queue-overview" id="queue-overview" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin: 20px 0;">
                <div class="queue-card" data-type="upload" style="background:#f0f8ff;padding:15px;border-left:4px solid #0073aa;">
                    <h3 style="margin-top:0;">Upload Queue</h3>
                    <p><strong>Pending:</strong> <span data-field="pending">0</span></p>
                    <p><strong>In Progress:</strong> <span data-field="in_progress">0</span></p>
                    <p><strong>Completed:</strong> <span data-field="completed">0</span></p>
                    <p><strong>Total Uploaded:</strong> <span data-field="completed_size">0 Bytes</span></p>
                </div>
                <div class="queue-card" data-type="download" style="background:#f8f7ff;padding:15px;border-left:4px solid #6f42c1;">
                    <h3 style="margin-top:0;">Download Queue</h3>
                    <p><strong>Pending:</strong> <span data-field="pending">0</span></p>
                    <p><strong>In Progress:</strong> <span data-field="in_progress">0</span></p>
                    <p><strong>Completed:</strong> <span data-field="completed">0</span></p>
                    <p><strong>Total Restored:</strong> <span data-field="completed_size">0 Bytes</span></p>
                </div>
                <div class="queue-card" data-type="alt" style="background:#f3fdf6;padding:15px;border-left:4px solid #2d9b4f;">
                    <h3 style="margin-top:0;">AI Alt Queue</h3>
                    <p><strong>Scheduled:</strong> <span data-field="pending">0</span></p>
                    <p><strong>In Progress:</strong> <span data-field="in_progress">0</span></p>
                    <p><strong>Completed:</strong> <span data-field="completed">0</span></p>
                    <p><strong>Skipped:</strong> <span data-field="skipped">0</span></p>
                </div>
            </div>
            <div class="logs-actions" style="margin-bottom: 20px;">
                <button type="button" id="manual-upload-all" class="button button-primary">Manual Upload All Local Files</button>
                <button type="button" id="retry-failed" class="button button-secondary">Retry Failed Operations</button>
            </div>
            
            <div class="logs-filters">
                <select id="status-filter">
                    <option value="">All Statuses</option>
                    <option value="requested">Requested</option>
                    <option value="in_progress">In Progress</option>
                    <option value="completed">Completed</option>
                    <option value="failed">Failed</option>
                </select>
                
                <select id="operation-filter">
                    <option value="">All Operations</option>
                    <option value="upload">Upload</option>
                    <option value="download">Download</option>
                    <option value="alt">Alt + AI</option>
                </select>
                
                <button type="button" id="refresh-logs" class="button">Refresh</button>
            </div>
            
            <div id="logs-container">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>File</th>
                            <th>Operation</th>
                            <th>Status</th>
                            <th>Size</th>
                            <th>Started</th>
                            <th>Completed</th>
                            <th>Notes</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="logs-tbody">
                        <!-- Logs will be loaded here via AJAX -->
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
    public function bulk_page() {
        $per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 50;
        $paged = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
        $offset = ($paged - 1) * $per_page;
        
        // Get all attachments first, then sort by actual file size
        global $wpdb;
        $total_attachments = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'");
        
        $attachments = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID, p.post_title, p.post_mime_type, pm1.meta_value as file_path, 
                pm2.meta_value as s3_key
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_wp_attached_file'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = 'enhanced_s3_key'
            WHERE p.post_type = 'attachment'
            ORDER BY p.ID DESC
            LIMIT %d OFFSET %d
        ", $per_page, $offset));
        $uploads_basedir = trailingslashit(wp_upload_dir()['basedir']);
        
        // Add actual file sizes and sort by size
        foreach ($attachments as $attachment) {
            $attachment->actual_file_size = $this->get_attachment_file_size($attachment->ID);
        }
        
        // Sort by file size (largest first)
        usort($attachments, function($a, $b) {
            return $b->actual_file_size - $a->actual_file_size;
        });
        
        $total_pages = ceil($total_attachments / $per_page);
        
        ?>
        <div class="wrap">
            <h1>S3 Bulk Operations</h1>
            <?php $this->render_media_scan_box('bulk'); ?>
            
            <div class="bulk-controls" style="margin-bottom: 20px;">
                <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                    <label>Files per page:</label>
                    <select id="per-page-select" onchange="changePage()">
                        <option value="25" <?php selected($per_page, 25); ?>>25</option>
                        <option value="50" <?php selected($per_page, 50); ?>>50</option>
                        <option value="100" <?php selected($per_page, 100); ?>>100</option>
                        <option value="200" <?php selected($per_page, 200); ?>>200</option>
                    </select>
                    
                    <div class="pagination-info">
                        Page <?php echo $paged; ?> of <?php echo $total_pages; ?> 
                        (<?php echo $total_attachments; ?> total files)
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                    <button type="button" id="bulk-upload" class="button button-primary" disabled>
                        Upload Selected to S3 (<span id="upload-count">0</span> files, <span id="upload-size">0 Bytes</span>)
                    </button>
                    <button type="button" id="bulk-download" class="button button-secondary" disabled>
                        Download Selected from S3 (<span id="download-count">0</span> files, <span id="download-size">0 Bytes</span>)
                    </button>
                    <?php if ($this->ai_alt_enabled): ?>
                    <button type="button" id="bulk-generate-alt" class="button" disabled>
                        Generate Alt Tags (AI)
                    </button>
                    <?php endif; ?>
                    <button type="button" id="select-all-local" class="button">Select All Local Files</button>
                    <button type="button" id="select-all-s3" class="button">Select All S3 Files</button>
                    <button type="button" id="clear-selection" class="button">Clear Selection</button>
                </div>
            </div>
            
            <div class="bulk-progress" id="bulk-progress" style="display: none;">
                <div class="progress-bar">
                    <div class="progress-fill" id="progress-fill" style="width: 0%; background: #0073aa; height: 20px;"></div>
                </div>
                <div class="progress-text" id="progress-text">Processing...</div>
            </div>
            
            <form id="bulk-operations-form">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td class="manage-column column-cb check-column">
                                <input type="checkbox" id="select-all">
                            </td>
                            <th>File</th>
                            <th>Type</th>
                            <th>Size</th>
                            <th>Status</th>
                            <th>Location</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attachments as $attachment): 
                            $file_size = $attachment->actual_file_size;
                            $is_on_s3 = !empty($attachment->s3_key);
                            $local_exists = !empty($attachment->file_path) && file_exists($uploads_basedir . $attachment->file_path);
                            $is_image = strpos($attachment->post_mime_type, 'image/') === 0;
                        ?>
                        <tr>
                            <th class="check-column">
                                <input type="checkbox" name="attachment_ids[]" value="<?php echo $attachment->ID; ?>" 
                                    data-is-s3="<?php echo $is_on_s3 ? '1' : '0'; ?>"
                                    data-local-exists="<?php echo $local_exists ? '1' : '0'; ?>"
                                    data-file-size="<?php echo $file_size; ?>"
                                    data-is-image="<?php echo $is_image ? '1' : '0'; ?>">
                            </th>
                            <td>
                                <strong><?php echo esc_html($attachment->post_title ?: basename($attachment->file_path)); ?></strong>
                                <br><small><?php echo esc_html($attachment->file_path); ?></small>
                            </td>
                            <td><?php echo esc_html($attachment->post_mime_type); ?></td>
                            <td><?php echo $this->format_file_size($file_size); ?></td>
                            <td>
                                <?php if ($is_on_s3 && $local_exists): ?>
                                    <span style="color: blue;">Both S3 & Local</span>
                                <?php elseif ($is_on_s3): ?>
                                    <span style="color: green;">S3 Only</span>
                                <?php elseif ($local_exists): ?>
                                    <span style="color: orange;">Local Only</span>
                                <?php else: ?>
                                    <span style="color: red;">Missing</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($is_on_s3): ?>
                                    <small title="<?php echo esc_attr($attachment->s3_key); ?>">
                                        S3: <?php echo esc_html(substr($attachment->s3_key, 0, 50) . '...'); ?>
                                    </small>
                                <?php endif; ?>
                                <?php if ($local_exists): ?>
                                    <small>Local: Available</small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>
            
            <!-- Pagination -->
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    $page_links = paginate_links(array(
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                        'total' => $total_pages,
                        'current' => $paged
                    ));
                    echo $page_links;
                    ?>
                </div>
            </div>
        </div>
        
        <script>
        function changePage() {
            const perPage = document.getElementById('per-page-select').value;
            window.location.href = '<?php echo admin_url('upload.php?page=enhanced-s3-bulk'); ?>&per_page=' + perPage;
        }
        </script>
        
        <script>
        jQuery(document).ready(function($) {
            
            function updateButtonStates() {
                var selectedBoxes = $('input[name="attachment_ids[]"]:checked');
                var uploadCount = 0;
                var downloadCount = 0;
                var uploadSize = 0;
                var downloadSize = 0;
                
                selectedBoxes.each(function() {
                    var $this = $(this);
                    var isS3 = $this.data('is-s3') == '1';
                    var localExists = $this.data('local-exists') == '1';
                    var fileSize = parseInt($this.data('file-size') || 0);
                    
                    if (localExists && !isS3) {
                        uploadCount++;
                        uploadSize += fileSize;
                    }
                    if (isS3) {
                        downloadCount++;
                        downloadSize += fileSize;
                    }
                });
                
                $('#upload-count').text(uploadCount);
                $('#download-count').text(downloadCount);
                $('#upload-size').text(formatFileSize(uploadSize));
                $('#download-size').text(formatFileSize(downloadSize));
                
                $('#bulk-upload').prop('disabled', uploadCount === 0);
                $('#bulk-download').prop('disabled', downloadCount === 0);
            }
            
            function formatFileSize(bytes) {
                if (bytes === 0) return '0 Bytes';
                var k = 1024;
                var sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
                var i = Math.floor(Math.log(bytes) / Math.log(k));
                return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
            }
            
            // Bind events manually
            $('#select-all').on('change', function() {
                $('input[name="attachment_ids[]"]').prop('checked', this.checked);
                updateButtonStates();
            });
            
            $(document).on('change', 'input[name="attachment_ids[]"]', function() {
                updateButtonStates();
            });
            
            $('#select-all-local').on('click', function() {
                $('input[name="attachment_ids[]"][data-local-exists="1"][data-is-s3="0"]').prop('checked', true);
                updateButtonStates();
            });
            
            $('#select-all-s3').on('click', function() {
                $('input[name="attachment_ids[]"][data-is-s3="1"]').prop('checked', true);
                updateButtonStates();
            });
            
            $('#clear-selection').on('click', function() {
                $('input[name="attachment_ids[]"]').prop('checked', false);
                updateButtonStates();
            });
            
            // Initial state
            updateButtonStates();
        });
        </script>
        <?php
    }
    private function get_attachment_file_size($attachment_id) {
        $metadata = wp_get_attachment_metadata($attachment_id);
        if (isset($metadata['filesize'])) {
            return $metadata['filesize'];
        }
        
        $file_path = get_attached_file($attachment_id);
        if ($file_path && file_exists($file_path)) {
            return filesize($file_path);
        }
        
        return 0;
    }

    private function format_file_size($bytes) {
        if ($bytes === 0) return '0 Bytes';
        
        $k = 1024;
        $sizes = ['Bytes', 'KB', 'MB', 'GB'];
        $i = floor(log($bytes) / log($k));
        
        return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
    }

    public function ajax_bulk_s3_upload() {
        check_ajax_referer('enhanced_s3_nonce', 'nonce');
        
        $attachment_ids = array_map('intval', $_POST['attachment_ids']);
        $results = array('success' => 0, 'failed' => 0, 'errors' => array());

        if (empty($attachment_ids)) {
            wp_send_json_error('No files selected');
        }

        if (!$this->ensure_bucket_available(reset($attachment_ids))) {
            wp_send_json_error('Unable to create S3 bucket automatically. Check AWS permissions.');
        }
        
        if (!$this->queue_manager) {
            wp_send_json_error('Queue manager not initialized');
        }
        
        foreach ($attachment_ids as $attachment_id) {
            try {
                $this->queue_manager->queue_upload($attachment_id, array(
                    'source' => 'media-bulk-page',
                    'initiator' => get_current_user_id()
                ));
                $results['success']++;
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = "ID {$attachment_id}: " . $e->getMessage();
            }
        }
        
        wp_send_json_success($results);
    }

    public function ajax_bulk_s3_download() {
        check_ajax_referer('enhanced_s3_nonce', 'nonce');
        
        $attachment_ids = array_map('intval', $_POST['attachment_ids']);
        $results = array('success' => 0, 'failed' => 0, 'errors' => array());
        
        foreach ($attachment_ids as $attachment_id) {
            try {
                $this->queue_manager->queue_download($attachment_id, array(
                    'source' => 'media-bulk-page',
                    'initiator' => get_current_user_id()
                ));
                $results['success']++;
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = "ID {$attachment_id}: " . $e->getMessage();
            }
        }
        
        wp_send_json_success($results);
    }

    public function ajax_optimize_media() {
        check_ajax_referer('enhanced_s3_nonce', 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error('Insufficient permissions');
        }

        if (!$this->optimize_media) {
            wp_send_json_error('Optimization is disabled in settings');
        }

        $attachment_id = intval($_POST['attachment_id'] ?? 0);
        if (!$attachment_id) {
            wp_send_json_error('Invalid attachment ID');
        }

        $result = $this->optimize_attachment_locally($attachment_id);
        if (!empty($result['success'])) {
            wp_send_json_success($result);
        }

        $error = isset($result['error']) ? $result['error'] : 'Unable to optimize media item';
        wp_send_json_error($error);
    }

    public function ajax_bulk_optimize_media() {
        check_ajax_referer('enhanced_s3_nonce', 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error('Insufficient permissions');
        }

        if (!$this->optimize_media) {
            wp_send_json_error('Optimization is disabled in settings');
        }

        $attachment_ids = isset($_POST['attachment_ids']) ? array_map('intval', (array) $_POST['attachment_ids']) : array();
        if (empty($attachment_ids)) {
            wp_send_json_error('No media selected');
        }

        $summary = array(
            'success' => 0,
            'failed' => 0,
            'errors' => array()
        );

        foreach ($attachment_ids as $attachment_id) {
            $result = $this->optimize_attachment_locally($attachment_id);
            if (!empty($result['success'])) {
                $summary['success']++;
            } else {
                $summary['failed']++;
                $summary['errors'][] = 'ID ' . $attachment_id . ': ' . ($result['error'] ?? 'Unable to optimize');
            }
        }

        wp_send_json_success($summary);
    }
    
    /**
     * AJAX: Setup AWS Resources
     */
    public function ajax_setup_aws_resources() {
        check_ajax_referer('enhanced_s3_nonce', 'nonce');
    
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        try {
            $result = $this->provision_aws_stack();
            wp_send_json_success($result);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    /**
     * AJAX: Queue S3 Upload
     */
    public function ajax_queue_s3_upload() {
        check_ajax_referer('enhanced_s3_nonce', 'nonce');
        
        $attachment_id = intval($_POST['attachment_id']);
        
        if (!$attachment_id) {
            wp_send_json_error('Invalid attachment ID');
        }
        
        if (!$this->ensure_bucket_available($attachment_id)) {
            wp_send_json_error('Unable to create S3 bucket automatically. Check AWS permissions.');
        }

        if (!$this->queue_manager) {
            wp_send_json_error('Queue manager not initialized');
        }
        
        try {
            $log_id = $this->queue_manager->queue_upload($attachment_id, array(
                'source' => 'media-single-ajax',
                'initiator' => get_current_user_id()
            ));
            wp_send_json_success(array('log_id' => $log_id));
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * AJAX: Queue S3 Download
     */
    public function ajax_queue_s3_download() {
        check_ajax_referer('enhanced_s3_nonce', 'nonce');
        
        $attachment_id = intval($_POST['attachment_id']);
        
        if (!$attachment_id) {
            wp_send_json_error('Invalid attachment ID');
        }
        
        if (!$this->queue_manager) {
            wp_send_json_error('Queue manager not initialized');
        }
        
        try {
            $log_id = $this->queue_manager->queue_download($attachment_id, array(
                'source' => 'media-single-ajax',
                'initiator' => get_current_user_id()
            ));
            wp_send_json_success(array('log_id' => $log_id));
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * AJAX: Get operation status
     */
    public function ajax_get_operation_status() {
        check_ajax_referer('enhanced_s3_nonce', 'nonce');
        
        $log_id = intval($_POST['log_id']);
        
        global $wpdb;
        $log = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->logs_table} WHERE id = %d",
            $log_id
        ));
        
        if (!$log) {
            wp_send_json_error('Log not found');
        }
        
        wp_send_json_success($log);
    }
    
    /**
     * AJAX: Get logs
     */
    public function ajax_get_logs() {
        check_ajax_referer('enhanced_s3_nonce', 'nonce');
        
        $status_filter = sanitize_text_field($_POST['status'] ?? '');
        $operation_filter = sanitize_text_field($_POST['operation'] ?? '');
        $limit = intval($_POST['limit'] ?? 50);
        $offset = intval($_POST['offset'] ?? 0);
        
        global $wpdb;
        
        $where_conditions = array();
        $where_values = array();
        
        if ($status_filter) {
            $where_conditions[] = "status = %s";
            $where_values[] = $status_filter;
        }
        
        if ($operation_filter) {
            $where_conditions[] = "operation_type = %s";
            $where_values[] = $operation_filter;
        }
        
        $where_clause = '';
        if ($where_conditions) {
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        }
        
        $where_values[] = $limit;
        $where_values[] = $offset;
        
        $query = "SELECT * FROM {$this->logs_table} 
                  {$where_clause} 
                  ORDER BY created_at DESC 
                  LIMIT %d OFFSET %d";
        
        $logs = $wpdb->get_results($wpdb->prepare($query, $where_values));
        if ($logs) {
            foreach ($logs as $log) {
                $log->job_meta = $log->job_meta ? maybe_unserialize($log->job_meta) : array();
            }
        }
        
        wp_send_json_success($logs);
    }
    
    /**
     * AJAX: Test S3 Connection
     */
    public function ajax_test_s3_connection() {
        check_ajax_referer('enhanced_s3_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        if (!$this->aws_sdk) {
            wp_send_json_error('AWS SDK not initialized. Please check your credentials.');
        }
        
        try {
            // Create a test file
            $test_content = 'This is a test file for S3 connection - ' . time();
            $test_key = 'test-connection-' . time() . '.txt';

            $temp_file = tempnam(sys_get_temp_dir(), 'test');
file_put_contents($temp_file, $test_content);
            
            // Try to upload to S3
            $upload_result = $this->aws_sdk->upload_file_to_s3(
                $temp_file,
                $this->bucket_name,
                $test_key,
                'text/plain'
            );

            unlink($temp_file);
            
            if ($upload_result['success']) {
                // Try to delete the test file
                $delete_result = $this->aws_sdk->delete_file_from_s3($this->bucket_name, $test_key);
                
                if ($delete_result['success']) {
                    wp_send_json_success('S3 connection successful! Upload and delete operations work correctly.');
                } else {
                    wp_send_json_success('S3 upload successful, but could not delete test file. Check delete permissions.');
                }
            } else {
                wp_send_json_error('S3 connection failed: ' . $upload_result['error']);
            }
            
        } catch (Exception $e) {
            wp_send_json_error('S3 connection error: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX: Test CloudFront Connection
     */
    public function ajax_test_cloudfront_connection() {
        check_ajax_referer('enhanced_s3_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        if (empty($this->cloudfront_domain)) {
            wp_send_json_error('CloudFront domain not configured');
        }
        
        try {
            // Test CloudFront domain accessibility
            $test_url = 'https://' . $this->cloudfront_domain . '/test-file.txt';
            $response = wp_remote_get($test_url, array('timeout' => 10));
            
            if (!is_wp_error($response)) {
                $status_code = wp_remote_retrieve_response_code($response);
                if ($status_code === 200 || $status_code === 404) {
                    wp_send_json_success('CloudFront connection successful! Domain is accessible.');
                } else {
                    wp_send_json_error('CloudFront returned HTTP status: ' . $status_code);
                }
            } else {
                wp_send_json_error('CloudFront connection failed: ' . $response->get_error_message());
            }
            
        } catch (Exception $e) {
            wp_send_json_error('CloudFront test error: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX: Test SQS Connection
     */
    public function ajax_test_sqs_connection() {
        check_ajax_referer('enhanced_s3_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        if (!$this->aws_sdk) {
            wp_send_json_error('AWS SDK not initialized');
        }
        
        if (empty($this->sqs_queue_url)) {
            wp_send_json_error('SQS queue URL not configured');
        }
        
        try {
            // Send test message to SQS
            $test_message = array(
                'operation' => 'test',
                'timestamp' => time()
            );
            
            $send_result = $this->aws_sdk->send_sqs_message($this->sqs_queue_url, $test_message);
            
            if ($send_result['success']) {
                wp_send_json_success('SQS connection successful! Test message sent to queue.');
            } else {
                wp_send_json_error('SQS connection failed: ' . $send_result['error']);
            }
            
        } catch (Exception $e) {
            wp_send_json_error('SQS test error: ' . $e->getMessage());
        }
    }

    private function optimize_attachment_locally($attachment_id) {
        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            return array('success' => false, 'error' => 'File not found on disk');
        }

        if (!wp_attachment_is_image($attachment_id)) {
            return array('success' => true, 'skipped' => true, 'message' => 'Optimization skipped for non-image files');
        }

        if (!$this->auto_resize_images && !$this->compress_images) {
            return array('success' => true, 'message' => 'No optimization rules enabled');
        }

        $original_size = filesize($file_path);
        $processing_path = $file_path;
        $temporary_files = array();
        $resize_details = null;
        $compression_details = null;

        if ($this->auto_resize_images) {
            $resize_details = $this->create_resized_copy_for_local_opt($attachment_id, $file_path);
            if (!empty($resize_details['success'])) {
                $processing_path = $resize_details['file_path'];
                $temporary_files[] = $processing_path;
                $this->update_attachment_dimensions_from_resize($attachment_id, $resize_details);
            } elseif (!empty($resize_details['error'])) {
                error_log('FeatherLift Media: Resize skipped for attachment ' . $attachment_id . ' - ' . $resize_details['error']);
            }
        }

        if ($this->compress_images) {
            $compressor_path = plugin_dir_path(__FILE__) . 'includes/image-compressor.php';
            if (file_exists($compressor_path)) {
                require_once $compressor_path;
                $compressor = new Enhanced_S3_Image_Compressor($this->get_runtime_options());
                if (!function_exists('wp_tempnam')) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                }
                $temp_file = wp_tempnam(basename($file_path));
                if (!$temp_file) {
                    $temp_file = tempnam(sys_get_temp_dir(), 'optimize_');
                }
                if ($temp_file) {
                    $result = $compressor->compress_image($processing_path, $temp_file);
                    if (!empty($result['success'])) {
                        $processing_path = $temp_file;
                        $compression_details = $result;
                        $temporary_files[] = $temp_file;
                    } else {
                        error_log('FeatherLift Media: Compression failed for attachment ' . $attachment_id . ' - ' . ($result['error'] ?? 'Unknown error'));
                        if (file_exists($temp_file)) {
                            unlink($temp_file);
                        }
                    }
                }
            }
        }

        if ($processing_path !== $file_path) {
            if (!copy($processing_path, $file_path)) {
                foreach ($temporary_files as $temp) {
                    if ($temp !== $file_path && file_exists($temp)) {
                        unlink($temp);
                    }
                }
                return array('success' => false, 'error' => 'Unable to replace original file');
            }
        }

        foreach ($temporary_files as $temp_file) {
            if ($temp_file !== $file_path && file_exists($temp_file)) {
                unlink($temp_file);
            }
        }

        clearstatcache(true, $file_path);
        $final_size = filesize($file_path);
        $savings = ($original_size > 0 && $final_size > 0)
            ? round((($original_size - $final_size) / $original_size) * 100, 1)
            : 0;

        update_post_meta($attachment_id, 'enhanced_s3_local_optimized', current_time('mysql'));
        update_post_meta($attachment_id, 'enhanced_s3_local_optimized_size', $final_size);
        update_post_meta($attachment_id, 'enhanced_s3_local_optimized_savings', $savings);

        if ($compression_details) {
            update_post_meta($attachment_id, 'enhanced_s3_compression_service', $compression_details['service_used'] ?? 'local');
        }

        return array(
            'success' => true,
            'resized' => !empty($resize_details['success']),
            'compressed' => !empty($compression_details),
            'original_size' => $original_size,
            'final_size' => $final_size,
            'savings_percent' => $savings
        );
    }

    private function create_resized_copy_for_local_opt($attachment_id, $file_path) {
        $max_width = isset($this->options['resize_max_width']) ? intval($this->options['resize_max_width']) : 0;
        $max_height = isset($this->options['resize_max_height']) ? intval($this->options['resize_max_height']) : 0;

        if ($max_width <= 0 && $max_height <= 0) {
            return array('success' => false, 'error' => 'Resize bounds not set');
        }

        if (!function_exists('wp_get_image_editor')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        if (!function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
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
            $temp_path = tempnam(sys_get_temp_dir(), 'resize_');
        }
        if (!$temp_path) {
            return array('success' => false, 'error' => 'Unable to create temp file for resize');
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

    private function update_attachment_dimensions_from_resize($attachment_id, $resize_details) {
        if (empty($resize_details['width']) || empty($resize_details['height'])) {
            return;
        }

        $metadata = wp_get_attachment_metadata($attachment_id);
        if (empty($metadata)) {
            return;
        }

        $metadata['width'] = $resize_details['width'];
        $metadata['height'] = $resize_details['height'];
        wp_update_attachment_metadata($attachment_id, $metadata);
    }
    public function add_media_fields($form_fields, $post) {
        $show_optimize = $this->optimize_media && wp_attachment_is_image($post->ID);
        $show_offload = $this->offload_media;

        if (!$show_optimize && !$show_offload) {
            return $form_fields;
        }
        
        $s3_key = get_post_meta($post->ID, 'enhanced_s3_key', true);
        $is_on_s3 = !empty($s3_key);
        $current_alt = get_post_meta($post->ID, '_wp_attachment_image_alt', true);
        $attachment_description = wp_strip_all_tags(get_post_field('post_content', $post->ID));
        $description_preview = $attachment_description !== ''
            ? wp_trim_words($attachment_description, 35, '…')
            : 'Not set';
        $optimized_at = get_post_meta($post->ID, 'enhanced_s3_local_optimized', true);
        
        ob_start();
        ?>
        <div class="enhanced-s3-controls">
            <?php if ($show_optimize): ?>
                <div class="enhanced-s3-block">
                    <p><strong>Local Optimization:</strong> <?php echo $optimized_at ? '<span style="color:green;">Last run ' . esc_html(human_time_diff(strtotime($optimized_at), current_time('timestamp'))) . ' ago</span>' : '<span style="color:#646970;">Not yet optimized</span>'; ?></p>
                    <button type="button" class="button enhanced-s3-optimize-btn" data-attachment-id="<?php echo $post->ID; ?>">
                        Optimize Now
                    </button>
                    <div class="operation-status" id="opt-status-<?php echo $post->ID; ?>" style="margin-top: 8px;"></div>
                </div>
            <?php endif; ?>

            <?php if ($show_offload): ?>
                <div class="enhanced-s3-block">
                    <p><strong>S3 Offload:</strong>
                        <?php if ($is_on_s3): ?>
                            <span style="color: green;">✓ On S3</span>
                        <?php else: ?>
                            <span style="color: orange;">Local only</span>
                        <?php endif; ?>
                    </p>
                    <?php if ($this->is_configured()): ?>
                        <?php if ($is_on_s3): ?>
                            <p><strong>S3 Key:</strong> <?php echo esc_html($s3_key); ?></p>
                            <button type="button" class="button" onclick="enhancedS3.queueDownload(<?php echo $post->ID; ?>)">
                                Download from S3
                            </button>
                        <?php else: ?>
                            <button type="button" class="button button-primary" onclick="enhancedS3.queueUpload(<?php echo $post->ID; ?>)">
                                Upload to S3
                            </button>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="description">Add AWS credentials in FeatherLift settings to enable offloading.</p>
                    <?php endif; ?>
                    <div class="operation-status" id="status-<?php echo $post->ID; ?>" style="margin-top: 10px;"></div>
                </div>
            <?php endif; ?>

            <?php if ($this->ai_alt_enabled && wp_attachment_is_image($post->ID)): ?>
                <hr />
                <div class="enhanced-s3-alt-tools">
                    <p><strong>Alt text:</strong> <span id="alt-preview-<?php echo $post->ID; ?>"><?php echo $current_alt ? esc_html($current_alt) : 'Not set'; ?></span></p>
                    <button type="button" class="button enhanced-s3-generate-alt" data-attachment-id="<?php echo $post->ID; ?>">
                        <?php esc_html_e('Generate Alt Tag', 'enhanced-s3'); ?>
                    </button>
                    <div class="enhanced-s3-alt-status" id="alt-status-<?php echo $post->ID; ?>" style="margin-top:8px;"></div>
                </div>
            <?php endif; ?>

            <hr />
            <div class="enhanced-s3-description-preview">
                <p><strong>Generated Description:</strong></p>
                <p id="description-preview-<?php echo $post->ID; ?>" style="margin:4px 0 0;"><?php echo esc_html($description_preview); ?></p>
                <?php if ($attachment_description !== ''): ?>
                    <p class="description" style="margin-top:4px;">Full text lives inside the attachment description field.</p>
                <?php else: ?>
                    <p class="description" style="margin-top:4px;">Set a description on the attachment to see it here.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php
        $html = ob_get_clean();
        
        $form_fields['enhanced_s3_controls'] = array(
            'label' => 'FeatherLift Media Controls',
            'input' => 'html',
            'html' => $html
        );
        
        return $form_fields;
    }

    public function register_media_bulk_actions($bulk_actions) {
        $bulk_actions['enhanced_s3_bulk_upload'] = 'Upload to S3';
        $bulk_actions['enhanced_s3_bulk_download'] = 'Download from S3';
        if ($this->ai_alt_enabled) {
            $bulk_actions['enhanced_s3_bulk_generate_alt'] = 'Generate Alt Tags (AI)';
        }
        return $bulk_actions;
    }

    public function handle_media_bulk_actions($redirect_to, $doaction, $post_ids) {
        if (!in_array($doaction, array('enhanced_s3_bulk_upload', 'enhanced_s3_bulk_download'), true)) {
            if ($doaction === 'enhanced_s3_bulk_generate_alt') {
                return $this->handle_bulk_alt_generation($redirect_to, $post_ids);
            }
            return $redirect_to;
        }

        if (empty($post_ids)) {
            return add_query_arg('enhanced_s3_bulk_error', 'empty', $redirect_to);
        }

        if (!$this->queue_manager) {
            return add_query_arg('enhanced_s3_bulk_error', 'queue', $redirect_to);
        }

        if ($doaction === 'enhanced_s3_bulk_upload' && !$this->ensure_bucket_available($post_ids[0])) {
            return add_query_arg('enhanced_s3_bulk_error', 'bucket', $redirect_to);
        }

        $success = 0;
        $failed = 0;

        foreach ($post_ids as $attachment_id) {
            try {
                if ($doaction === 'enhanced_s3_bulk_upload') {
                    $this->queue_manager->queue_upload($attachment_id, array(
                        'source' => 'media-library-bulk-action',
                        'initiator' => get_current_user_id()
                    ));
                } else {
                    $this->queue_manager->queue_download($attachment_id, array(
                        'source' => 'media-library-bulk-action',
                        'initiator' => get_current_user_id()
                    ));
                }
                $success++;
            } catch (Exception $e) {
                $failed++;
            }
        }

        return add_query_arg(array(
            'enhanced_s3_bulk_action' => $doaction,
            'enhanced_s3_bulk_success' => $success,
            'enhanced_s3_bulk_failed' => $failed
        ), $redirect_to);
    }

    private function handle_bulk_alt_generation($redirect_to, $post_ids) {
        if (!$this->ai_alt_enabled) {
            return add_query_arg('enhanced_s3_alt_error', 'disabled', $redirect_to);
        }

        if (empty($post_ids)) {
            return add_query_arg('enhanced_s3_alt_error', 'empty', $redirect_to);
        }

        $summary = array('success' => 0, 'failed' => 0, 'skipped' => 0);
        $batch_key = uniqid('media-bulk-', true);
        foreach ($post_ids as $attachment_id) {
            $result = $this->generate_ai_alt_text($attachment_id, false, array(
                'source' => 'media-bulk-action',
                'initiator' => get_current_user_id(),
                'batch' => $batch_key
            ));
            if (!empty($result['skipped'])) {
                $summary['skipped']++;
            } elseif (!empty($result['success'])) {
                $summary['success']++;
            } else {
                $summary['failed']++;
            }
        }

        return add_query_arg(array(
            'enhanced_s3_alt_success' => $summary['success'],
            'enhanced_s3_alt_failed' => $summary['failed'],
            'enhanced_s3_alt_skipped' => $summary['skipped']
        ), $redirect_to);
    }

    private function register_post_bulk_hooks() {
        if (!$this->ai_alt_enabled) {
            return;
        }

        $post_types = get_post_types(array('public' => true), 'names');
        foreach ($post_types as $post_type) {
            if ($post_type === 'attachment') {
                continue;
            }
            add_filter('bulk_actions-edit-' . $post_type, array($this, 'add_post_alt_bulk_action'));
            add_filter('handle_bulk_actions-edit-' . $post_type, array($this, 'handle_post_alt_bulk_action'), 10, 3);
        }
    }

    public function add_post_alt_bulk_action($bulk_actions) {
        if ($this->ai_alt_enabled) {
            $bulk_actions['enhanced_s3_generate_post_alt'] = 'Generate Alt Tags (AI)';
        }
        return $bulk_actions;
    }

    public function handle_post_alt_bulk_action($redirect_to, $doaction, $post_ids) {
        if ($doaction !== 'enhanced_s3_generate_post_alt') {
            return $redirect_to;
        }

        if (!$this->ai_alt_enabled) {
            return add_query_arg('enhanced_s3_post_alt_error', 'disabled', $redirect_to);
        }

        if (empty($post_ids)) {
            return add_query_arg('enhanced_s3_post_alt_error', 'empty', $redirect_to);
        }

        $summary = $this->process_post_alt_jobs($post_ids);

        return add_query_arg(array(
            'enhanced_s3_post_alt_success' => $summary['success'],
            'enhanced_s3_post_alt_failed' => $summary['failed'],
            'enhanced_s3_post_alt_skipped' => $summary['skipped'],
            'enhanced_s3_post_alt_attachments' => $summary['attachments']
        ), $redirect_to);
    }

    private function process_post_alt_jobs($post_ids) {
        $summary = array('success' => 0, 'failed' => 0, 'skipped' => 0, 'attachments' => 0);
        $processed = array();
        $batch_key = uniqid('post-bulk-', true);

        foreach ($post_ids as $post_id) {
            $attachment_ids = $this->collect_post_attachment_ids($post_id);
            foreach ($attachment_ids as $attachment_id) {
                if (isset($processed[$attachment_id])) {
                    continue;
                }
                $processed[$attachment_id] = true;
                $summary['attachments']++;
                $result = $this->generate_ai_alt_text($attachment_id, false, array(
                    'source' => 'post-list',
                    'post_id' => $post_id,
                    'initiator' => get_current_user_id(),
                    'batch' => $batch_key
                ));
                if (!empty($result['skipped'])) {
                    $summary['skipped']++;
                } elseif (!empty($result['success'])) {
                    $summary['success']++;
                } else {
                    $summary['failed']++;
                }
            }
        }

        return $summary;
    }

    private function collect_post_attachment_ids($post_id) {
        $ids = array();

        $thumb_id = get_post_thumbnail_id($post_id);
        if ($thumb_id) {
            $ids[] = $thumb_id;
        }

        $children = get_posts(array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'post_parent' => $post_id,
            'fields' => 'ids',
            'post_mime_type' => 'image'
        ));
        if (!empty($children)) {
            $ids = array_merge($ids, $children);
        }

        $content = get_post_field('post_content', $post_id);
        if ($content && preg_match_all('/wp-image-(\d+)/', $content, $matches)) {
            foreach ($matches[1] as $match_id) {
                $ids[] = (int) $match_id;
            }
        }

        $ids = array_filter(array_map('absint', array_unique($ids)));
        return array_values($ids);
    }

    public function render_media_bulk_notice() {
        if (!empty($_GET['enhanced_s3_post_alt_error'])) {
            $code = sanitize_text_field(wp_unslash($_GET['enhanced_s3_post_alt_error']));
            $message = 'AI alt generation failed for the selected posts.';
            if ($code === 'disabled') {
                $message = 'Enable AI alt text automation in FeatherLift Media settings to use this bulk action.';
            } elseif ($code === 'empty') {
                $message = 'Select at least one post before running Generate Alt Tags.';
            }
            echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
            return;
        }

        if (isset($_GET['enhanced_s3_post_alt_success'])) {
            $success = absint($_GET['enhanced_s3_post_alt_success']);
            $failed = isset($_GET['enhanced_s3_post_alt_failed']) ? absint($_GET['enhanced_s3_post_alt_failed']) : 0;
            $skipped = isset($_GET['enhanced_s3_post_alt_skipped']) ? absint($_GET['enhanced_s3_post_alt_skipped']) : 0;
            $attachments = isset($_GET['enhanced_s3_post_alt_attachments']) ? absint($_GET['enhanced_s3_post_alt_attachments']) : 0;
            $parts = array();
            if ($attachments) {
                $parts[] = sprintf('%d image(s) analyzed', $attachments);
            }
            if ($success) {
                $parts[] = sprintf('%d alt tag(s) created', $success);
            }
            if ($skipped) {
                $parts[] = sprintf('%d skipped', $skipped);
            }
            if ($failed) {
                $parts[] = sprintf('%d failed', $failed);
            }
            $class = $failed ? 'notice-warning' : 'notice-success';
            echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html(implode(' • ', array_filter($parts))) . '</p></div>';
            return;
        }

        if (!empty($_GET['enhanced_s3_alt_error'])) {
            $code = sanitize_text_field(wp_unslash($_GET['enhanced_s3_alt_error']));
            $message = 'AI alt generation failed.';
            if ($code === 'disabled') {
                $message = 'Enable AI alt text automation in FeatherLift Media settings first.';
            } elseif ($code === 'empty') {
                $message = 'Select at least one image before running Generate Alt Tags.';
            }
            echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
            return;
        }

        if (isset($_GET['enhanced_s3_alt_success'])) {
            $success = absint($_GET['enhanced_s3_alt_success']);
            $failed = isset($_GET['enhanced_s3_alt_failed']) ? absint($_GET['enhanced_s3_alt_failed']) : 0;
            $skipped = isset($_GET['enhanced_s3_alt_skipped']) ? absint($_GET['enhanced_s3_alt_skipped']) : 0;
            $class = $failed ? 'notice-warning' : 'notice-success';
            $parts = array();
            if ($success) {
                $parts[] = sprintf('%d alt tag(s) generated', $success);
            }
            if ($skipped) {
                $parts[] = sprintf('%d skipped (already had alt text)', $skipped);
            }
            if ($failed) {
                $parts[] = sprintf('%d failed', $failed);
            }
            echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html(implode(' • ', array_filter($parts))) . '</p></div>';
            return;
        }

        if (!empty($_GET['enhanced_s3_bulk_error'])) {
            $error = sanitize_text_field(wp_unslash($_GET['enhanced_s3_bulk_error']));
            $message = 'FeatherLift Media bulk action failed.';
            if ($error === 'queue') {
                $message = 'FeatherLift Media could not initialize the queue. Please verify your AWS credentials.';
            } elseif ($error === 'bucket') {
                $message = 'FeatherLift Media was unable to create the target bucket automatically. Check AWS permissions.';
            } elseif ($error === 'empty') {
                $message = 'No media files were selected for the bulk action.';
            }
            echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
            return;
        }

        if (empty($_GET['enhanced_s3_bulk_action'])) {
            return;
        }

        $action = sanitize_text_field(wp_unslash($_GET['enhanced_s3_bulk_action']));
        $success = isset($_GET['enhanced_s3_bulk_success']) ? absint($_GET['enhanced_s3_bulk_success']) : 0;
        $failed = isset($_GET['enhanced_s3_bulk_failed']) ? absint($_GET['enhanced_s3_bulk_failed']) : 0;
        $is_upload = $action === 'enhanced_s3_bulk_upload';
        $label = $is_upload ? 'upload' : 'download';
        $class = $failed > 0 ? 'notice-warning' : 'notice-success';
        $message = sprintf(
            'FeatherLift Media queued %1$d %2$s request(s)%3$s.',
            $success,
            $label,
            $failed > 0 ? sprintf(' (%d failed)', $failed) : ''
        );
        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }
    
    /**
     * Process SQS queue (called by cron)
     */
    public function process_sqs_queue() {
        
        if ($this->queue_manager) {
            $this->queue_manager->process_queue();
        } else {
            error_log('ERROR: Queue manager not initialized');
        }
    }
    
    /**
     * Check if plugin is configured
     */
    private function is_configured() {
        return !empty($this->access_key) && !empty($this->secret_key) && !empty($this->region);
    }
    
    private function get_s3_endpoint($region) {
        $endpoints = array(
            'us-east-1' => 's3.amazonaws.com',
            'us-east-2' => 's3.us-east-2.amazonaws.com',
            'us-west-1' => 's3.us-west-1.amazonaws.com',
            'us-west-2' => 's3.us-west-2.amazonaws.com',
            'ca-central-1' => 's3.ca-central-1.amazonaws.com',
            'ap-south-1' => 's3.ap-south-1.amazonaws.com',
            'ap-northeast-1' => 's3.ap-northeast-1.amazonaws.com',
            'ap-northeast-2' => 's3.ap-northeast-2.amazonaws.com',
            'ap-southeast-1' => 's3.ap-southeast-1.amazonaws.com',
            'ap-southeast-2' => 's3.ap-southeast-2.amazonaws.com',
            'eu-central-1' => 's3.eu-central-1.amazonaws.com',
            'eu-west-1' => 's3.eu-west-1.amazonaws.com',
            'eu-west-2' => 's3.eu-west-2.amazonaws.com',
            'eu-west-3' => 's3.eu-west-3.amazonaws.com',
            'eu-north-1' => 's3.eu-north-1.amazonaws.com',
            'sa-east-1' => 's3.sa-east-1.amazonaws.com'
        );
        
        return isset($endpoints[$region]) ? $endpoints[$region] : 's3.amazonaws.com';
    }

    private function has_stored_secret($key) {
        return isset($this->options[$key]) && $this->options[$key] !== '';
    }

    private function encrypt_sensitive_value($value) {
        $value = trim((string) $value);
        if ($value === '' || !function_exists('openssl_encrypt')) {
            return $value;
        }

        try {
            $key = $this->get_encryption_key();
            if (function_exists('random_bytes')) {
                $iv = random_bytes(16);
            } elseif (function_exists('openssl_random_pseudo_bytes')) {
                $iv = openssl_random_pseudo_bytes(16);
            } else {
                $iv = substr(hash('sha256', microtime(true) . wp_rand()), 0, 16);
            }
            $encrypted = openssl_encrypt($value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

            if ($encrypted === false) {
                return $value;
            }

            return 'enc::' . base64_encode($iv . $encrypted);
        } catch (Exception $e) {
            return $value;
        }
    }

    private function decrypt_sensitive_value($value) {
        if ($value === '' || strpos($value, 'enc::') !== 0 || !function_exists('openssl_decrypt')) {
            return $value;
        }

        $payload = base64_decode(substr($value, 5), true);
        if ($payload === false || strlen($payload) <= 16) {
            return '';
        }

        $iv = substr($payload, 0, 16);
        $ciphertext = substr($payload, 16);
        $key = $this->get_encryption_key();

        $decrypted = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        return $decrypted !== false ? $decrypted : '';
    }

    private function get_encryption_key() {
        if (function_exists('wp_salt')) {
            $salt = wp_salt('secure_auth');
        } else {
            $candidates = array(
                defined('SECURE_AUTH_SALT') ? SECURE_AUTH_SALT : '',
                defined('AUTH_SALT') ? AUTH_SALT : '',
                defined('LOGGED_IN_SALT') ? LOGGED_IN_SALT : '',
                defined('NONCE_SALT') ? NONCE_SALT : '',
                defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '',
                defined('AUTH_KEY') ? AUTH_KEY : '',
                defined('LOGGED_IN_KEY') ? LOGGED_IN_KEY : '',
                defined('NONCE_KEY') ? NONCE_KEY : ''
            );
            $salt = '';
            foreach ($candidates as $candidate) {
                if (!empty($candidate)) {
                    $salt .= '|' . $candidate;
                }
            }

            if ($salt === '') {
                $system_fingerprint = function_exists('php_uname') ? php_uname() : 'featherlift-media';
                $salt = hash('sha256', __FILE__ . '|' . $system_fingerprint . '|' . microtime(true));
            }
        }

        $identifier = defined('ABSPATH') ? ABSPATH : __DIR__;
        return hash('sha256', $salt . '|' . $identifier, true);
    }

    private function ensure_bucket_available($attachment_id = null) {
        if (!empty($this->bucket_name)) {
            return true;
        }

        if (!$this->aws_sdk) {
            return false;
        }

        $bucket_name = $this->generate_bucket_name($attachment_id);
        $result = $this->aws_sdk->create_s3_bucket($bucket_name, array(
            'preserve_permissions' => $this->preserve_bucket_permissions
        ));

        if (empty($result['success'])) {
            error_log('FeatherLift Media: Unable to create bucket automatically - ' . ($result['error'] ?? 'Unknown error'));
            return false;
        }

        $this->bucket_name = $bucket_name;
        $this->options['bucket_name'] = $bucket_name;
        update_option('enhanced_s3_settings', $this->options);
        if ($this->queue_manager && $this->aws_sdk) {
            $this->queue_manager = new Enhanced_S3_Queue_Manager($this->aws_sdk, $this->get_runtime_options());
        }
        return true;
    }

    private function generate_bucket_name($attachment_id = null) {
        $base = sanitize_title(get_bloginfo('name'));

        if ($this->bucket_autoname_strategy === 'file' && $attachment_id) {
            $file_path = get_attached_file($attachment_id);
            if ($file_path) {
                $filename = pathinfo($file_path, PATHINFO_FILENAME);
                $base = strtolower(preg_replace('/[^a-z0-9-]/', '-', $filename));
            }
        }

        if (empty($base)) {
            $base = 'wp-media';
        }

        $base = trim(preg_replace('/-+/', '-', $base), '-');
        if (strlen($base) < 3) {
            $base = 'wp-media';
        }

        $unique = substr(md5($base . microtime(true) . wp_rand()), 0, 8);
        $base = substr($base, 0, 40);

        return $base . '-' . $unique;
    }

    private function create_log_entry($attachment_id, $operation_type, $status = 'requested', $args = array()) {
        global $wpdb;

        $file_path = get_attached_file($attachment_id);
        $file_name = get_the_title($attachment_id);
        if (empty($file_name) && $file_path) {
            $file_name = basename($file_path);
        }
        if (empty($file_name)) {
            $file_name = 'Attachment ' . $attachment_id;
        }

        $file_size = null;
        if (!empty($args['file_size'])) {
            $file_size = absint($args['file_size']);
        } elseif ($file_path && file_exists($file_path)) {
            $file_size = filesize($file_path);
        }

        $job_meta = array();
        if (!empty($args['job_meta'])) {
            $job_meta = $args['job_meta'];
        }

        $inserted = $wpdb->insert(
            $this->logs_table,
            array(
                'attachment_id' => $attachment_id,
                'operation_type' => $operation_type,
                'status' => $status,
                'file_name' => $file_name,
                'file_size' => $file_size,
                'job_meta' => !empty($job_meta) ? maybe_serialize($job_meta) : null,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s', '%d', '%s', '%s')
        );

        return $inserted ? (int) $wpdb->insert_id : 0;
    }

    private function update_log_entry($log_id, $status, $args = array()) {
        if (!$log_id) {
            return;
        }

        global $wpdb;
        $data = array(
            'status' => $status,
            'updated_at' => current_time('mysql')
        );
        $formats = array('%s', '%s');

        if ($status === 'in_progress') {
            $data['started_at'] = current_time('mysql');
            $formats[] = '%s';
        }

        if ($status === 'completed') {
            $data['completed_at'] = current_time('mysql');
            $formats[] = '%s';
        }

        if (!empty($args['error'])) {
            $data['error_message'] = $args['error'];
            $formats[] = '%s';
        }

        if (isset($args['file_size'])) {
            $data['file_size'] = absint($args['file_size']);
            $formats[] = '%d';
        }

        if (!empty($args['job_meta'])) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT job_meta FROM {$this->logs_table} WHERE id = %d",
                $log_id
            ));
            $stored = $existing ? maybe_unserialize($existing) : array();
            if (!is_array($stored)) {
                $stored = array();
            }
            $merged = array_merge($stored, $args['job_meta']);
            $data['job_meta'] = maybe_serialize($merged);
            $formats[] = '%s';
        }

        $wpdb->update(
            $this->logs_table,
            $data,
            array('id' => $log_id),
            $formats,
            array('%d')
        );
    }

    private function generate_ai_alt_text($attachment_id, $overwrite = false, $context = array()) {
        if (!$this->ai_alt_enabled) {
            return array('success' => false, 'error' => 'AI automation is disabled.');
        }

        if (!$attachment_id || !wp_attachment_is_image($attachment_id)) {
            return array('success' => false, 'error' => 'Only image attachments are supported.');
        }

        $job_context = wp_parse_args($context, array(
            'source' => 'manual',
            'initiator' => get_current_user_id(),
            'overwrite' => $overwrite ? 1 : 0
        ));

        $log_id = $this->create_log_entry($attachment_id, 'alt', 'requested', array(
            'job_meta' => $job_context
        ));

        $existing_alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        if ($existing_alt && !$overwrite && $this->ai_skip_existing_alt) {
            $this->update_log_entry($log_id, 'skipped', array(
                'job_meta' => array('message' => 'Alt text already existed')
            ));
            return array(
                'success' => true,
                'skipped' => true,
                'alt_text' => $existing_alt,
                'attachment_id' => $attachment_id,
                'log_id' => $log_id,
                'message' => 'Alt text already exists.'
            );
        }

        $image_payload = $this->get_smallest_image_candidate($attachment_id);
        if (empty($image_payload['data_url'])) {
            $this->update_log_entry($log_id, 'failed', array(
                'error' => 'Unable to read an image rendition for analysis.'
            ));
            return array('success' => false, 'error' => 'Unable to read an image rendition for analysis.', 'log_id' => $log_id);
        }

        $this->update_log_entry($log_id, 'in_progress');

        $prompt_context = array(
            'site_brief' => $this->ai_site_brief,
            'title' => get_the_title($attachment_id),
            'caption' => wp_get_attachment_caption($attachment_id),
            'description' => wp_strip_all_tags(get_post_field('post_content', $attachment_id)),
            'filename' => basename($image_payload['path'])
        );

        $prompt = $this->build_ai_prompt($prompt_context);
        $ai_result = $this->call_ai_for_alt_text($this->ai_agent, $this->ai_model, $prompt, $image_payload);

        if (empty($ai_result['success'])) {
            $this->update_log_entry($log_id, 'failed', array(
                'error' => $ai_result['error'] ?? 'AI provider did not return alt text.'
            ));
            return array('success' => false, 'error' => $ai_result['error'] ?? 'AI provider did not return alt text.', 'log_id' => $log_id);
        }

        $alt_text = $this->sanitize_alt_text($ai_result['alt_text']);
        if ($alt_text === '') {
            $this->update_log_entry($log_id, 'failed', array(
                'error' => 'AI response was empty.'
            ));
            return array('success' => false, 'error' => 'AI response was empty.', 'log_id' => $log_id);
        }

        update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
        update_post_meta($attachment_id, '_enhanced_s3_alt_generated', current_time('mysql'));

        $this->update_log_entry($log_id, 'completed', array(
            'file_size' => $image_payload['size'] ?? null,
            'job_meta' => array('alt_text' => $alt_text)
        ));

        return array(
            'success' => true,
            'alt_text' => $alt_text,
            'attachment_id' => $attachment_id,
            'log_id' => $log_id
        );
    }

    private function get_smallest_image_candidate($attachment_id) {
        $candidates = array();
        $primary_path = get_attached_file($attachment_id);
        if ($primary_path && file_exists($primary_path)) {
            $size = filesize($primary_path);
            if ($size !== false) {
                $candidates[$primary_path] = $size;
            }
        }

        $metadata = wp_get_attachment_metadata($attachment_id);
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            $upload_dir = wp_upload_dir();
            $base_relative = isset($metadata['file']) ? $metadata['file'] : '';
            $base_dir = $base_relative
                ? trailingslashit(dirname($upload_dir['basedir'] . '/' . $base_relative))
                : ($primary_path ? trailingslashit(dirname($primary_path)) : trailingslashit($upload_dir['basedir']));
            foreach ($metadata['sizes'] as $size) {
                if (empty($size['file'])) {
                    continue;
                }
                $size_path = $base_dir . $size['file'];
                if (file_exists($size_path)) {
                    $size = filesize($size_path);
                    if ($size !== false) {
                        $candidates[$size_path] = $size;
                    }
                }
            }
        }

        if (empty($candidates)) {
            return array();
        }

        asort($candidates, SORT_NUMERIC);
        $path = key($candidates);
        $data = @file_get_contents($path);
        if ($data === false) {
            return array();
        }

        $mime = wp_check_filetype($path)['type'] ?? 'image/jpeg';
        $base64 = base64_encode($data);

        return array(
            'path' => $path,
            'mime' => $mime,
            'size' => strlen($data),
            'base64' => $base64,
            'data_url' => 'data:' . $mime . ';base64,' . $base64
        );
    }

    private function build_ai_prompt($context) {
        $lines = array();
        $lines[] = 'Create accessible alt text (max 20 words) describing the literal contents of the image.';
        $lines[] = 'Do not guess names, genders, or famous people unless explicitly provided. Avoid phrases like "image of".';
        $lines[] = 'Use neutral tone, describe composition, mood, and key objects.';
        if (!empty($context['site_brief'])) {
            $lines[] = 'Site brief: ' . $context['site_brief'];
        }
        if (!empty($context['title'])) {
            $lines[] = 'Image title: ' . $context['title'];
        }
        if (!empty($context['caption'])) {
            $lines[] = 'Caption: ' . $context['caption'];
        }
        if (!empty($context['description'])) {
            $lines[] = 'Description: ' . $context['description'];
        }
        $lines[] = 'Filename: ' . $context['filename'];
        $lines[] = 'Return only the alt text sentence.';
        return implode("\n", array_filter($lines));
    }

    private function call_ai_for_alt_text($agent, $model, $prompt, $image_payload) {
        switch ($agent) {
            case 'anthropic':
                return $this->call_anthropic_for_alt($model, $prompt, $image_payload);
            case 'custom':
                return $this->call_custom_ai_for_alt($prompt, $image_payload);
            case 'openai':
            default:
                return $this->call_openai_for_alt($model, $prompt, $image_payload);
        }
    }

    private function call_openai_for_alt($model, $prompt, $image_payload) {
        if (empty($this->openai_api_key)) {
            return array('success' => false, 'error' => 'OpenAI API key missing.');
        }

        $body = array(
            'model' => $model,
            'messages' => array(
                array('role' => 'system', 'content' => 'You are an accessibility assistant that writes concise, literal alt text.'),
                array(
                    'role' => 'user',
                    'content' => array(
                        array('type' => 'text', 'text' => $prompt),
                        array('type' => 'image_url', 'image_url' => array('url' => $image_payload['data_url']))
                    )
                )
            ),
            'max_tokens' => 120,
            'temperature' => 0.2
        );

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->openai_api_key
            ),
            'body' => wp_json_encode($body),
            'timeout' => 45
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $payload = json_decode(wp_remote_retrieve_body($response), true);
        $text = $payload['choices'][0]['message']['content'] ?? '';

        if (!$text) {
            return array('success' => false, 'error' => 'OpenAI response empty.');
        }

        return array('success' => true, 'alt_text' => $text);
    }

    private function call_anthropic_for_alt($model, $prompt, $image_payload) {
        if (empty($this->anthropic_api_key)) {
            return array('success' => false, 'error' => 'Anthropic API key missing.');
        }

        $body = array(
            'model' => $model,
            'max_tokens' => 150,
            'system' => 'You produce short, literal alt text without guessing identities.',
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => array(
                        array('type' => 'text', 'text' => $prompt),
                        array('type' => 'image', 'source' => array(
                            'type' => 'base64',
                            'media_type' => $image_payload['mime'],
                            'data' => $image_payload['base64']
                        ))
                    )
                )
            )
        );

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key' => $this->anthropic_api_key,
                'anthropic-version' => '2023-06-01'
            ),
            'body' => wp_json_encode($body),
            'timeout' => 45
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $payload = json_decode(wp_remote_retrieve_body($response), true);
        $text = '';
        if (!empty($payload['content'])) {
            foreach ($payload['content'] as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $text .= ' ' . $block['text'];
                }
            }
        }

        $text = trim($text);
        if ($text === '') {
            return array('success' => false, 'error' => 'Anthropic response empty.');
        }

        return array('success' => true, 'alt_text' => $text);
    }

    private function call_custom_ai_for_alt($prompt, $image_payload) {
        if (empty($this->custom_ai_endpoint)) {
            return array('success' => false, 'error' => 'Custom endpoint URL missing.');
        }

        $body = array(
            'prompt' => $prompt,
            'image' => array(
                'mime_type' => $image_payload['mime'],
                'data' => $image_payload['base64']
            )
        );

        $headers = array('Content-Type' => 'application/json');
        if (!empty($this->custom_ai_api_key)) {
            $headers['Authorization'] = 'Bearer ' . $this->custom_ai_api_key;
        }

        $response = wp_remote_post($this->custom_ai_endpoint, array(
            'headers' => $headers,
            'body' => wp_json_encode($body),
            'timeout' => 45
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $payload = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($payload['alt_text'])) {
            return array('success' => false, 'error' => 'Custom endpoint did not return alt_text.');
        }

        return array('success' => true, 'alt_text' => $payload['alt_text']);
    }

    private function sanitize_alt_text($text) {
        $text = trim(wp_strip_all_tags((string) $text));
        $text = preg_replace('/\s+/', ' ', $text);
        if (strlen($text) > 180) {
            $text = substr($text, 0, 177) . '…';
        }
        return $text;
    }
    
    // Field callback methods
    public function intent_section_callback() {
        echo '<p>Choose the workflow you want to enable, then save changes to unlock the matching settings panels. You can enable optimization only, offload only, or chain both so every upload is compressed before landing on S3/CloudFront.</p>';
    }

    public function optimize_section_callback() {
        echo '<p>Define how images are resized and which compression service (TinyPNG, ImageOptim, or native PHP) should run before files leave WordPress. Images wider than ' . absint($this->default_resize_cap) . 'px will be capped automatically.</p>';
    }

    public function offload_section_callback() {
        echo '<p>Provide the credentials or manual endpoints needed to keep media on S3 and optionally accelerate through CloudFront. You can let the plugin auto-provision resources or plug in existing infrastructure.</p>';
    }

    public function automation_section_callback() {
        echo '<p>Control which file types are processed automatically after upload so future content follows the same optimization/offload workflow.</p>';
    }

    public function aws_section_callback() {
        echo '<p>Enter your AWS credentials to enable S3 integration.</p>';
    }
    
    public function options_section_callback() {
        echo '<p>Configure your upload preferences.</p>';
    }
    
    public function access_key_field() {
        $has_value = $this->has_stored_secret('access_key');
        $placeholder = $has_value ? '********' : '';
        echo '<input type="text" name="enhanced_s3_settings[access_key]" value="" class="regular-text" placeholder="' . esc_attr($placeholder) . '">';
        echo '<input type="hidden" name="enhanced_s3_settings[access_key_masked]" value="' . ($has_value ? '1' : '0') . '">';
        echo '<input type="hidden" name="enhanced_s3_settings[access_key_clear]" value="0">';
        if ($has_value) {
            echo '<p class="description">Stored securely. Leave blank to keep. <button type="button" class="button-link enhanced-s3-clear-secret" data-field="access_key">Remove stored key</button></p>';
        } else {
            echo '<p class="description">Enter your AWS access key ID</p>';
        }
    }
    
    public function secret_key_field() {
        $has_value = $this->has_stored_secret('secret_key');
        echo '<input type="password" name="enhanced_s3_settings[secret_key]" value="" class="regular-text" placeholder="' . ($has_value ? '********' : '') . '">';
        echo '<input type="hidden" name="enhanced_s3_settings[secret_key_masked]" value="' . ($has_value ? '1' : '0') . '">';
        echo '<input type="hidden" name="enhanced_s3_settings[secret_key_clear]" value="0">';
        if ($has_value) {
            echo '<p class="description">Stored securely. Leave blank to keep. <button type="button" class="button-link enhanced-s3-clear-secret" data-field="secret_key">Remove stored key</button></p>';
        } else {
            echo '<p class="description">Enter your AWS secret access key</p>';
        }
    }
    
    public function region_field() {
        $value = $this->get_option('region', 'us-east-1');
        $regions = array(
            'us-east-1' => 'US East (N. Virginia)',
            'us-east-2' => 'US East (Ohio)',
            'us-west-1' => 'US West (N. California)',
            'us-west-2' => 'US West (Oregon)',
            'ca-central-1' => 'Canada (Central)',
            'ap-south-1' => 'Asia Pacific (Mumbai)',
            'ap-northeast-1' => 'Asia Pacific (Tokyo)',
            'ap-northeast-2' => 'Asia Pacific (Seoul)',
            'ap-southeast-1' => 'Asia Pacific (Singapore)',
            'ap-southeast-2' => 'Asia Pacific (Sydney)',
            'eu-central-1' => 'EU (Frankfurt)',
            'eu-west-1' => 'EU (Ireland)',
            'eu-west-2' => 'EU (London)',
            'eu-west-3' => 'EU (Paris)',
            'eu-north-1' => 'EU (Stockholm)',
            'sa-east-1' => 'South America (São Paulo)'
        );
        
        echo '<select name="enhanced_s3_settings[region]">';
        foreach ($regions as $code => $name) {
            echo '<option value="' . esc_attr($code) . '" ' . selected($value, $code, false) . '>' . esc_html($name) . '</option>';
        }
        echo '</select>';
    }

    public function bucket_name_field() {
        $value = $this->get_option('bucket_name');
        $is_configured = !empty($value);
        
        if ($is_configured) {
            // Bucket already configured - show as disabled
            echo '<input type="text" name="enhanced_s3_settings[bucket_name]" value="' . esc_attr($value) . '" class="regular-text" disabled>';
            echo '<input type="hidden" name="enhanced_s3_settings[bucket_name]" value="' . esc_attr($value) . '">';
            echo '<p class="description" style="color: #d63638;">Bucket name is locked after creation to prevent breaking URLs.</p>';
        } else {
            // Not configured yet - allow custom input
            echo '<input type="text" name="enhanced_s3_settings[bucket_name]" value="' . esc_attr($value) . '" class="regular-text" placeholder="my-custom-bucket-name">';
            echo '<p class="description">Optional: Enter custom S3 bucket name (lowercase, numbers, hyphens only). Leave blank for auto-generated name.</p>';
        }
    }

    public function bucket_autoname_strategy_field() {
        $value = $this->get_option('bucket_autoname_strategy', 'file');
        $choices = array(
            'file' => 'Match the first uploaded file name',
            'site' => 'Use the site title'
        );
        echo '<select name="enhanced_s3_settings[bucket_autoname_strategy]">';
        foreach ($choices as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($value, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">Automatically create a unique S3 bucket when none is configured.</p>';
    }

    public function preserve_bucket_permissions_field() {
        $value = $this->get_option('preserve_bucket_permissions', true);
        echo '<label><input type="checkbox" name="enhanced_s3_settings[preserve_bucket_permissions]" value="1" ' . checked($value, true, false) . '> Keep existing S3 bucket policies untouched</label>';
        echo '<p class="description">Disable if you want the plugin to configure public read/CORS rules automatically.</p>';
    }

    public function optimize_media_field() {
        $value = $this->get_option('optimize_media', false);
        echo '<label><input type="checkbox" id="enhanced-s3-enable-optimization" name="enhanced_s3_settings[optimize_media]" value="1" ' . checked($value, true, false) . '> Optimize images (TinyPNG/ImageOptim/PHP + smart resize)</label>';
        echo '<p class="description">Keeps originals safe but delivers slimmer assets. Required for TinyPNG + auto-resize workflows.</p>';
    }

    public function offload_media_field() {
        $value = $this->get_option('offload_media', false);
        echo '<label><input type="checkbox" id="enhanced-s3-enable-offload" name="enhanced_s3_settings[offload_media]" value="1" ' . checked($value, true, false) . '> Store media on Amazon S3 and optionally serve via CloudFront</label>';
        echo '<p class="description">When enabled, uploads are moved off the web server and delivered from cloud storage/CDN.</p>';
    }

    public function s3_prefix_field() {
        $value = trim($this->get_option('s3_prefix', 'wp-content/uploads/'));
        echo '<input type="text" name="enhanced_s3_settings[s3_prefix]" value="' . esc_attr($value) . '" class="regular-text" placeholder="wp-content/uploads/">';
        echo '<p class="description">Folder prefix inside your bucket. Defaults to <code>wp-content/uploads/</code>.</p>';
    }

    public function cloudfront_domain_field() {
        $value = $this->get_option('cloudfront_domain', '');
        echo '<input type="text" name="enhanced_s3_settings[cloudfront_domain]" value="' . esc_attr($value) . '" class="regular-text" placeholder="dxxxx.cloudfront.net">';
        echo '<p class="description">Optional: point to an existing CloudFront distribution instead of provisioning a new one.</p>';
    }

    public function cloudfront_distribution_id_field() {
        $value = $this->get_option('cloudfront_distribution_id', '');
        echo '<input type="text" name="enhanced_s3_settings[cloudfront_distribution_id]" value="' . esc_attr($value) . '" class="regular-text" placeholder="E123ABC456">';
        echo '<p class="description">Store the distribution ID if you plan to manage CloudFront manually.</p>';
    }

    public function use_cloudfront_field() {
        $value = $this->get_option('use_cloudfront');
        $has_s3_files = $this->has_existing_s3_files();
        
        if ($has_s3_files && !empty($this->cloudfront_domain)) {
            echo '<input type="hidden" name="enhanced_s3_settings[use_cloudfront]" value="1">';
            echo '<input type="checkbox" checked disabled> <strong>Enabled (locked - files already using CloudFront)</strong>';
            echo '<p class="description" style="color: #d63638;">CloudFront cannot be disabled after files have been uploaded with CloudFront URLs.</p>';
        } elseif ($has_s3_files && empty($this->cloudfront_domain)) {
            echo '<input type="checkbox" name="enhanced_s3_settings[use_cloudfront]" value="1" ' . checked($value, true, false) . ' disabled>';
            echo '<p class="description" style="color: #d63638;">CloudFront cannot be enabled after files have been uploaded without it. This would break existing URLs.</p>';
        } else {
            echo '<input type="checkbox" name="enhanced_s3_settings[use_cloudfront]" value="1" ' . checked($value, true, false) . '>';
            echo '<p class="description">Automatically create and use CloudFront distribution</p>';
        }
    }

    private function has_existing_s3_files() {
        global $wpdb;
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'enhanced_s3_key'");
        return $count > 0;
    }

    private function get_available_ai_agents() {
        return array(
            'openai'   => 'OpenAI',
            'anthropic'=> 'Anthropic Claude',
            'custom'   => 'Custom HTTPS Endpoint'
        );
    }

    private function get_ai_model_map() {
        return array(
            'openai' => array(
                'gpt-4o-mini' => 'GPT-4o Mini (Vision)',
                'gpt-4o'      => 'GPT-4o',
                'gpt-4.1'     => 'GPT-4.1',
                'gpt-4.1-mini'=> 'GPT-4.1 Mini'
            ),
            'anthropic' => array(
                'claude-3-5-sonnet-20240620' => 'Claude 3.5 Sonnet',
                'claude-3-opus-20240229'     => 'Claude 3 Opus',
                'claude-3-haiku-20240307'    => 'Claude 3 Haiku'
            ),
            'custom' => array(
                'generic' => 'Custom Endpoint'
            )
        );
    }

    private function get_models_for_agent($agent) {
        $map = $this->get_ai_model_map();
        return isset($map[$agent]) ? $map[$agent] : $map['openai'];
    }
    
    public function upload_thumbnails_field() {
        $value = $this->get_option('upload_thumbnails', true);
        echo '<input type="checkbox" name="enhanced_s3_settings[upload_thumbnails]" value="1" ' . checked($value, true, false) . '>';
        echo '<p class="description">Upload all thumbnail sizes along with the main image</p>';
    }
    
    public function auto_delete_local_field() {
        $value = $this->get_option('auto_delete_local');
        echo '<input type="checkbox" name="enhanced_s3_settings[auto_delete_local]" value="1" ' . checked($value, true, false) . '>';
        echo '<p class="description">Automatically delete local files after successful S3 upload</p>';
    }

    public function ai_section_callback() {
        echo '<p>Use computer vision to generate accurate, brand-safe alt text for your images. The AI receives the smallest available rendition plus your site context, image title, and caption.</p>';
    }

    public function ai_alt_enabled_field() {
        $value = $this->get_option('ai_alt_enabled', false);
        echo '<label><input type="checkbox" id="enhanced-s3-ai-enabled" name="enhanced_s3_settings[ai_alt_enabled]" value="1" ' . checked($value, true, false) . '> Enable AI-powered alt tags</label>';
        echo '<p class="description">Adds "Generate Alt Tag" buttons to the Media Library and attachment panels.</p>';
    }

    public function ai_site_brief_field() {
        $value = $this->get_option('ai_site_brief', get_bloginfo('description'));
        echo '<textarea name="enhanced_s3_settings[ai_site_brief]" rows="3" class="large-text" placeholder="Describe your business, tone, and audience">' . esc_textarea($value) . '</textarea>';
        echo '<p class="description">Shared with the AI so it can stay on-brand. Do not include secrets.</p>';
    }

    public function ai_agent_field() {
        $current = $this->get_option('ai_agent', 'openai');
        $agents = $this->get_available_ai_agents();
        echo '<select name="enhanced_s3_settings[ai_agent]" id="enhanced-s3-ai-agent" data-model-map="' . esc_attr(wp_json_encode($this->get_ai_model_map())) . '">';
        foreach ($agents as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($current, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    public function ai_model_field() {
        $agent = $this->get_option('ai_agent', 'openai');
        $current_model = $this->get_option('ai_model', 'gpt-4o-mini');
        $models = $this->get_models_for_agent($agent);
        echo '<select name="enhanced_s3_settings[ai_model]" id="enhanced-s3-ai-model">';
        foreach ($models as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($current_model, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">Only models compatible with the selected provider are shown.</p>';
    }

    public function ai_skip_existing_alt_field() {
        $value = $this->get_option('ai_skip_existing_alt', true);
        echo '<label><input type="checkbox" name="enhanced_s3_settings[ai_skip_existing_alt]" value="1" ' . checked($value, true, false) . '> Leave attachments alone when they already have alt text</label>';
    }

    public function openai_api_key_field() {
        $this->render_ai_secret_field('openai_api_key', 'OpenAI API key', 'enhanced-s3-ai-credential', 'openai');
    }

    public function anthropic_api_key_field() {
        $this->render_ai_secret_field('anthropic_api_key', 'Anthropic API key', 'enhanced-s3-ai-credential', 'anthropic');
    }

    public function custom_ai_endpoint_field() {
        $value = $this->get_option('custom_ai_endpoint');
        $style = $this->ai_agent === 'custom' ? '' : ' style="display:none;"';
        echo '<div class="enhanced-s3-ai-credential" data-agent="custom"' . $style . '>';
        echo '<input type="url" class="regular-text" name="enhanced_s3_settings[custom_ai_endpoint]" value="' . esc_attr($value) . '" placeholder="https://example.com/vision-endpoint">';
        echo '<p class="description">POST endpoint that accepts JSON payloads (prompt, base64 image) and responds with {"alt_text": "..."}.</p>';
        echo '</div>';
    }

    public function custom_ai_api_key_field() {
        $this->render_ai_secret_field('custom_ai_api_key', 'Custom endpoint API key (optional)', 'enhanced-s3-ai-credential', 'custom');
    }

    private function render_ai_secret_field($field, $description, $class, $agent_slug) {
        $has_value = $this->has_stored_secret($field);
        $style = $this->ai_agent === $agent_slug ? '' : ' style="display:none;"';
        echo '<div class="' . esc_attr($class) . '" data-agent="' . esc_attr($agent_slug) . '"' . $style . '>';
        echo '<input type="password" name="enhanced_s3_settings[' . esc_attr($field) . ']" value="" class="regular-text" placeholder="' . ($has_value ? '********' : '') . '">';
        echo '<input type="hidden" name="enhanced_s3_settings[' . esc_attr($field) . '_masked]" value="' . ($has_value ? '1' : '0') . '">';
        echo '<input type="hidden" name="enhanced_s3_settings[' . esc_attr($field) . '_clear]" value="0">';
        $desc = esc_html($description);
        if ($has_value) {
            $desc .= ' — stored securely.';
            $desc .= ' <button type="button" class="button-link enhanced-s3-clear-secret" data-field="' . esc_attr($field) . '">Remove stored key</button>';
        }
        echo '<p class="description">' . $desc . '</p>';
        echo '</div>';
    }
    
    // Add this method to the main plugin class:
    public function ajax_get_log_stats() {
        check_ajax_referer('enhanced_s3_nonce', 'nonce');
        
        global $wpdb;
        $logs_table = $wpdb->prefix . 'amagraphs_s3_logs';
        
        $status_rows = $wpdb->get_results("
            SELECT operation_type, status, COUNT(*) as count
            FROM {$logs_table}
            GROUP BY operation_type, status
        ");

        $overview = array();
        foreach ($status_rows as $row) {
            $type = $row->operation_type ?: 'upload';
            if (!isset($overview[$type])) {
                $overview[$type] = array(
                    'requested' => 0,
                    'in_progress' => 0,
                    'completed' => 0,
                    'failed' => 0,
                    'skipped' => 0
                );
            }
            $overview[$type][$row->status] = (int) $row->count;
        }

        $totals_rows = $wpdb->get_results("
            SELECT 
                operation_type,
                COUNT(*) as total_files,
                SUM(file_size) as total_size,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_files,
                SUM(CASE WHEN status = 'completed' THEN file_size ELSE 0 END) as completed_size
            FROM {$logs_table}
            GROUP BY operation_type
        ");

        $totals = array();
        foreach ($totals_rows as $row) {
            $type = $row->operation_type ?: 'upload';
            $totals[$type] = array(
                'total_files' => (int) $row->total_files,
                'total_size' => (int) $row->total_size,
                'completed_files' => (int) $row->completed_files,
                'completed_size' => (int) $row->completed_size
            );
        }

        wp_send_json_success(array(
            'overview' => $overview,
            'totals' => $totals
        ));
    }

    public function sanitize_settings($settings) {
        $new_settings = array();
        $existing_settings = get_option('enhanced_s3_settings', array());
        
        // Text fields
        $text_fields = array(
            'region', 
            'bucket_name',  // Allow bucket name to be saved
            's3_prefix', 
            'cloudfront_domain', 
            'cloudfront_distribution_id', 
            'sqs_queue_url',
            'compression_service',
            'compression_quality',
            'bucket_autoname_strategy',
            'resize_max_width',
            'resize_max_height',
            'ai_agent',
            'ai_model'
        );
        
        foreach ($text_fields as $field) {
            if (isset($settings[$field])) {
                // Save the value even if empty (user might be clearing it)
                $new_settings[$field] = sanitize_text_field($settings[$field]);
            } else {
                // Preserve existing value if not in submitted form
                $new_settings[$field] = isset($existing_settings[$field]) ? $existing_settings[$field] : '';
            }
        }

        // Sensitive fields (encrypted)
        foreach ($this->sensitive_fields as $field) {
            $incoming = isset($settings[$field]) ? trim($settings[$field]) : '';
            $masked = isset($settings[$field . '_masked']) && $settings[$field . '_masked'] === '1';
            $clear = isset($settings[$field . '_clear']) && $settings[$field . '_clear'] === '1';

            if ($clear) {
                $new_settings[$field] = '';
            } elseif ($incoming !== '') {
                $new_settings[$field] = $this->encrypt_sensitive_value(sanitize_text_field($incoming));
            } elseif ($masked && isset($existing_settings[$field])) {
                $new_settings[$field] = $existing_settings[$field];
            } else {
                $new_settings[$field] = '';
            }
        }
        
        // Checkbox fields
        $checkbox_fields = array(
            'use_cloudfront', 
            'upload_thumbnails', 
            'auto_delete_local',
            'compress_images',
            'email_notifications',
            'auto_upload_new_files',
            'preserve_bucket_permissions',
            'auto_resize_images',
            'ai_alt_enabled',
            'ai_skip_existing_alt',
            'optimize_media',
            'offload_media'
        );
        
        foreach ($checkbox_fields as $field) {
            $new_settings[$field] = isset($settings[$field]) && $settings[$field] === '1' ? '1' : '';
        }

        // Handle file types array
        if (isset($settings['auto_upload_file_types']) && is_array($settings['auto_upload_file_types'])) {
            $new_settings['auto_upload_file_types'] = array_map('sanitize_text_field', $settings['auto_upload_file_types']);
        } else {
            $new_settings['auto_upload_file_types'] = array('image');
        }

        if (isset($new_settings['bucket_autoname_strategy']) && !in_array($new_settings['bucket_autoname_strategy'], array('site', 'file'), true)) {
            $new_settings['bucket_autoname_strategy'] = 'file';
        }

        $new_settings['resize_max_width'] = isset($new_settings['resize_max_width']) ? max(0, intval($new_settings['resize_max_width'])) : 0;
        $new_settings['resize_max_height'] = isset($new_settings['resize_max_height']) ? max(0, intval($new_settings['resize_max_height'])) : 0;

        $allowed_agents = array_keys($this->get_available_ai_agents());
        if (empty($new_settings['ai_agent']) || !in_array($new_settings['ai_agent'], $allowed_agents, true)) {
            $new_settings['ai_agent'] = 'openai';
        }

        $models = $this->get_models_for_agent($new_settings['ai_agent']);
        if (empty($new_settings['ai_model']) || !isset($models[$new_settings['ai_model']])) {
            $new_settings['ai_model'] = array_key_first($models);
        }

        $new_settings['ai_site_brief'] = isset($settings['ai_site_brief']) ? sanitize_textarea_field($settings['ai_site_brief']) : '';
        $new_settings['custom_ai_endpoint'] = isset($settings['custom_ai_endpoint']) ? esc_url_raw(trim($settings['custom_ai_endpoint'])) : '';
        
        return $new_settings;
    }
    
    /**
     * URL replacement methods
     */
    public function get_attachment_url($url, $attachment_id) {
        $s3_key = get_post_meta($attachment_id, 'enhanced_s3_key', true);
        $stored_url = get_post_meta($attachment_id, 'enhanced_s3_url', true);
        
        if (!empty($s3_key)) {
            $resolved = $this->get_s3_url($s3_key);
            if (!empty($resolved)) {
                return $resolved;
            }
        }
        
        if (!empty($stored_url)) {
            return esc_url($stored_url);
        }
        
        return $url;
    }
    public function update_image_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
        if (empty($sources)) {
            return $sources;
        }
        
        $s3_key = get_post_meta($attachment_id, 'enhanced_s3_key', true);
        $stored_url = get_post_meta($attachment_id, 'enhanced_s3_url', true);
        
        if (!empty($s3_key)) {
            $s3_dir = dirname($s3_key);
            foreach ($sources as $width => $source) {
                $filename = basename($source['url']);
                $thumb_s3_key = trim($s3_dir, '/') . '/' . $filename;
                $s3_url = $this->get_s3_url($thumb_s3_key);
                if ($s3_url) {
                    $sources[$width]['url'] = $s3_url;
                }
            }
            return $sources;
        }
        
        if (!empty($stored_url)) {
            $base = trailingslashit(untrailingslashit(dirname($stored_url)));
            foreach ($sources as $width => $source) {
                $filename = basename($source['url']);
                $sources[$width]['url'] = esc_url($base . $filename);
            }
        }
        
        return $sources;
    }

    /**
     * Get S3 URL for a given key
     */
    private function get_s3_url($s3_key) {
        if ($this->use_cloudfront && !empty($this->cloudfront_domain)) {
            return 'https://' . $this->cloudfront_domain . '/' . $s3_key;
        }

        if (empty($this->bucket_name) || empty($this->s3_endpoint)) {
            return '';
        }

        return 'https://' . $this->bucket_name . '.' . $this->s3_endpoint . '/' . $s3_key;
    }

    // Replace the ajax_download_all_s3_files method
    // Update the ajax_download_all_s3_files method
    public function ajax_download_all_s3_files() {
        check_ajax_referer('enhanced_s3_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        try {
            global $wpdb;
            
            // Get all attachments with S3 metadata
            $s3_attachments = $wpdb->get_results("
                SELECT p.ID, pm.meta_value as s3_key
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                WHERE p.post_type = 'attachment'
                AND pm.meta_key = 'enhanced_s3_key'
                AND pm.meta_value != ''
            ");
            
            $file_count = count($s3_attachments);
            
            if ($file_count === 0) {
                wp_send_json_error('No S3 files found to download');
            }
            
            // Send start notification
            $this->send_restore_notification('restore_started', array(
                'file_count' => $file_count,
                'estimated_time' => $this->estimate_download_time($file_count)
            ));
            
            // Store restore start time
            update_option('enhanced_s3_restore_start_time', time());
            update_option('enhanced_s3_restore_total_files', $file_count);
            update_option('enhanced_s3_restore_completed_files', 0);
            
            $queued = 0;
            $failed = 0;
            
            foreach ($s3_attachments as $attachment) {
                try {
                    $this->queue_manager->queue_download($attachment->ID, array(
                        'source' => 'restore-all',
                        'initiator' => get_current_user_id()
                    ));
                    $queued++;
                } catch (Exception $e) {
                    $failed++;
                    error_log("Failed to queue download for attachment {$attachment->ID}: " . $e->getMessage());
                }
            }
            
            $message = "Restore started! Queued {$queued} files for download from S3";
            if ($failed > 0) {
                $message .= " ({$failed} failed to queue)";
            }
            $message .= ". You'll receive an email when the restore is complete.";
            
            wp_send_json_success($message);
            
        } catch (Exception $e) {
            // Send failure notification
            $this->send_restore_notification('restore_failed', array(
                'error' => $e->getMessage(),
                'downloaded' => 0
            ));
            
            wp_send_json_error('Download queue failed: ' . $e->getMessage());
        }
    }

    private function estimate_download_time($file_count) {
        if ($file_count <= 10) return '1-2 minutes';
        if ($file_count <= 50) return '5-10 minutes';
        if ($file_count <= 200) return '15-30 minutes';
        return '30+ minutes';
    }

    public function prevent_deactivation() {
        // Check if there are S3 files
        global $wpdb;
        $s3_files_count = $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->postmeta} 
            WHERE meta_key = 'enhanced_s3_key' AND meta_value != ''
        ");
        
        if ($s3_files_count > 0) {
            // Reactivate the plugin
            activate_plugin(plugin_basename(__FILE__));
            
            // Show warning message
            wp_die(
                '<h1>Plugin Deactivation Prevented</h1>
                <p><strong>Warning:</strong> This plugin manages ' . $s3_files_count . ' files stored on Amazon S3.</p>
                <p>Deactivating this plugin without properly restoring your files could result in broken media links on your website.</p>
                <p><strong>To safely remove this plugin:</strong></p>
                <ol>
                    <li>Go to <a href="' . admin_url('options-general.php?page=enhanced-s3-settings') . '">FeatherLift Media Settings</a></li>
                    <li>Use the "Reset AWS Configuration" option</li>
                    <li>Choose to download all S3 files back to local storage</li>
                    <li>Then you can safely deactivate the plugin</li>
                </ol>
                <p><a href="' . admin_url('plugins.php') . '">← Back to Plugins</a> | 
                <a href="' . admin_url('options-general.php?page=enhanced-s3-settings') . '">Go to Settings →</a></p>',
                'Plugin Deactivation Prevented',
                array('back_link' => true)
            );
        }
    }

    public function deletion_protection_notice() {
        global $pagenow;
        
        if ($pagenow === 'plugins.php') {
            global $wpdb;
            $s3_files_count = $wpdb->get_var("
                SELECT COUNT(*) FROM {$wpdb->postmeta} 
                WHERE meta_key = 'enhanced_s3_key' AND meta_value != ''
            ");
            
            if ($s3_files_count > 0) {
                echo '<div class="notice notice-warning">
                    <p><strong>FeatherLift Media:</strong> This plugin is managing ' . $s3_files_count . ' files on Amazon S3. 
                    <a href="' . admin_url('options-general.php?page=enhanced-s3-settings') . '">Restore files locally</a> before deactivating to prevent broken media links.</p>
                </div>';
            }
        }
    }

    private function process_s3_files($download_files, $clear_s3_bucket) {
        global $wpdb;
        
        $results = array(
            'downloaded' => 0,
            'download_failed' => 0,
            'deleted_from_s3' => 0,
            'delete_failed' => 0
        );
        
        // Get all attachments with S3 metadata
        $s3_attachments = $wpdb->get_results("
            SELECT p.ID, pm.meta_value as s3_key
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'attachment'
            AND pm.meta_key = 'enhanced_s3_key'
            AND pm.meta_value != ''
        ");
        
        foreach ($s3_attachments as $attachment) {
            if ($download_files) {
                try {
                    $this->download_attachment_from_s3($attachment->ID, $attachment->s3_key);
                    $results['downloaded']++;
                } catch (Exception $e) {
                    $results['download_failed']++;
                    error_log("Failed to download attachment {$attachment->ID}: " . $e->getMessage());
                }
            }
            
            if ($clear_s3_bucket && $this->aws_sdk) {
                try {
                    // Delete main file
                    $delete_result = $this->aws_sdk->delete_file_from_s3($this->bucket_name, $attachment->s3_key);
                    if ($delete_result['success']) {
                        $results['deleted_from_s3']++;
                    } else {
                        $results['delete_failed']++;
                    }
                    
                    // Delete thumbnails
                    $this->delete_s3_thumbnails($attachment->ID, $attachment->s3_key);
                    
                } catch (Exception $e) {
                    $results['delete_failed']++;
                    error_log("Failed to delete S3 file {$attachment->s3_key}: " . $e->getMessage());
                }
            }
        }
        
        return $results;
    }

    private function should_auto_upload_file_type($attachment_id) {
        $allowed_types = $this->get_option('auto_upload_file_types', array('image'));
        
        if (in_array('all', $allowed_types)) {
            return true;
        }
        
        $mime_type = get_post_mime_type($attachment_id);
        
        foreach ($allowed_types as $type) {
            switch ($type) {
                case 'image':
                    if (strpos($mime_type, 'image/') === 0) return true;
                    break;
                case 'video':
                    if (strpos($mime_type, 'video/') === 0) return true;
                    break;
                case 'audio':
                    if (strpos($mime_type, 'audio/') === 0) return true;
                    break;
                case 'document':
                    if (in_array($mime_type, array('application/pdf', 'application/msword', 'text/plain'))) return true;
                    break;
            }
        }
        
        return false;
    }

    private function download_attachment_from_s3($attachment_id, $s3_key) {
        if (!$this->aws_sdk) {
            throw new Exception('AWS SDK not available');
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
        
        // Download main file
        $download_result = $this->aws_sdk->download_file_from_s3(
            $this->bucket_name,
            $s3_key,
            $local_path
        );
        
        if (!$download_result['success']) {
            throw new Exception($download_result['error']);
        }
        
        // Update attachment file path
        update_attached_file($attachment_id, $local_path);
        
        // Download thumbnails
        if (wp_attachment_is_image($attachment_id)) {
            $this->download_s3_thumbnails($attachment_id, $s3_key, $local_path);
            
            // Regenerate thumbnails
            $metadata = wp_generate_attachment_metadata($attachment_id, $local_path);
            wp_update_attachment_metadata($attachment_id, $metadata);
        }
    }

    private function delete_aws_resources() {
        $results = array(
            'bucket_deleted' => false,
            'queue_deleted' => false,
            'cloudfront_deleted' => false,
            'errors' => array()
        );
        
        if (!$this->aws_sdk) {
            $results['errors'][] = 'AWS SDK not available';
            return $results;
        }
        
        try {
            // Delete S3 bucket
            if (!empty($this->bucket_name)) {
                // First, delete all objects in bucket
                $this->empty_s3_bucket();
                
                // Then delete the bucket
                $delete_result = $this->aws_sdk->delete_s3_bucket($this->bucket_name);
                if ($delete_result['success']) {
                    $results['bucket_deleted'] = true;
                } else {
                    $results['errors'][] = 'Failed to delete S3 bucket: ' . $delete_result['error'];
                }
            }
            
            // Delete SQS queue
            if (!empty($this->sqs_queue_url)) {
                $delete_result = $this->aws_sdk->delete_sqs_queue($this->sqs_queue_url);
                if ($delete_result['success']) {
                    $results['queue_deleted'] = true;
                } else {
                    $results['errors'][] = 'Failed to delete SQS queue: ' . $delete_result['error'];
                }
            }
            
            // Delete CloudFront distribution
            if (!empty($this->cloudfront_distribution_id)) {
                $delete_result = $this->aws_sdk->delete_cloudfront_distribution($this->cloudfront_distribution_id);
                if ($delete_result['success']) {
                    $results['cloudfront_deleted'] = true;
                } else {
                    $results['errors'][] = 'Failed to delete CloudFront distribution: ' . $delete_result['error'];
                }
            }
            
        } catch (Exception $e) {
            $results['errors'][] = 'AWS deletion error: ' . $e->getMessage();
        }
        
        return $results;
    }

    private function empty_s3_bucket() {
        if (!$this->aws_sdk || empty($this->bucket_name)) {
            return;
        }
        
        try {
            // List all objects in bucket
            $objects = $this->aws_sdk->list_s3_objects($this->bucket_name);
            
            if ($objects['success'] && !empty($objects['objects'])) {
                // Delete all objects
                foreach ($objects['objects'] as $object) {
                    $this->aws_sdk->delete_file_from_s3($this->bucket_name, $object['Key']);
                }
            }
        } catch (Exception $e) {
            error_log('Failed to empty S3 bucket: ' . $e->getMessage());
        }
    }

    private function delete_s3_thumbnails($attachment_id, $main_s3_key) {
        $metadata = wp_get_attachment_metadata($attachment_id);
        
        if (empty($metadata['sizes'])) {
            return;
        }
        
        $s3_dir = dirname($main_s3_key);
        
        foreach ($metadata['sizes'] as $size_info) {
            if (isset($size_info['file'])) {
                $thumb_s3_key = $s3_dir . '/' . $size_info['file'];
                $this->aws_sdk->delete_file_from_s3($this->bucket_name, $thumb_s3_key);
            }
        }
    }

    private function download_s3_thumbnails($attachment_id, $main_s3_key, $main_local_path) {
        $metadata = wp_get_attachment_metadata($attachment_id);
        
        if (empty($metadata['sizes'])) {
            return;
        }
        
        $local_dir = dirname($main_local_path);
        $s3_dir = dirname($main_s3_key);
        
        foreach ($metadata['sizes'] as $size_info) {
            if (isset($size_info['file'])) {
                $thumb_s3_key = $s3_dir . '/' . $size_info['file'];
                $thumb_local_path = $local_dir . '/' . $size_info['file'];
                
                $this->aws_sdk->download_file_from_s3(
                    $this->bucket_name,
                    $thumb_s3_key,
                    $thumb_local_path
                );
            }
        }
    }

    private function reset_plugin_configuration() {
        global $wpdb;
        
        // Clear all S3 metadata from attachments
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE 'enhanced_s3_%'");
        
        // Clear operation logs
        $logs_table = $wpdb->prefix . 'amagraphs_s3_logs';
        $wpdb->query("TRUNCATE TABLE {$logs_table}");
        
        // Reset settings but keep credentials and preferences
        $current_settings = get_option('enhanced_s3_settings', array());
        $reset_settings = array(
            // Keep these
            'access_key' => $current_settings['access_key'] ?? '',
            'secret_key' => $current_settings['secret_key'] ?? '',
            'region' => $current_settings['region'] ?? 'us-east-1',
            'compress_images' => $current_settings['compress_images'] ?? '',
            'compression_service' => $current_settings['compression_service'] ?? 'php_native',
            'compression_quality' => $current_settings['compression_quality'] ?? 85,
            'tinypng_api_key' => $current_settings['tinypng_api_key'] ?? '',
            'upload_thumbnails' => $current_settings['upload_thumbnails'] ?? '1',
            'auto_delete_local' => $current_settings['auto_delete_local'] ?? '',
            'use_cloudfront' => $current_settings['use_cloudfront'] ?? '',
            'bucket_autoname_strategy' => $current_settings['bucket_autoname_strategy'] ?? 'file',
            'preserve_bucket_permissions' => $current_settings['preserve_bucket_permissions'] ?? '1',
            'auto_resize_images' => $current_settings['auto_resize_images'] ?? '',
            'resize_max_width' => $current_settings['resize_max_width'] ?? 0,
            'resize_max_height' => $current_settings['resize_max_height'] ?? 0,
            
            // Reset these
            'bucket_name' => '',
            's3_prefix' => 'wp-content/uploads/',
            'cloudfront_domain' => '',
            'cloudfront_distribution_id' => '',
            'sqs_queue_url' => ''
        );
        
        update_option('enhanced_s3_settings', $reset_settings);
        
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
    }
}

// Initialize the plugin
new Enhanced_S3_Media_Upload();

// Deactivation hook to clean up cron
register_deactivation_hook(__FILE__, function() {
    $timestamp = wp_next_scheduled('enhanced_s3_process_queue');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'enhanced_s3_process_queue');
    }
});