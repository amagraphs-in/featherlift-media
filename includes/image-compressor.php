<?php
/**
 * Image Compression Handler for FeatherLift Media Plugin
 */

class Enhanced_S3_Image_Compressor {
    private $options;
    
    public function __construct($options) {
        $this->options = $options;
    }
    
    /**
     * Compress image file
     */
    public function compress_image($file_path, $output_path = null) {
        if (!$this->options['compress_images']) {
            return array('success' => true, 'file_path' => $file_path, 'original_size' => filesize($file_path), 'compressed_size' => filesize($file_path));
        }
        
        $output_path = $output_path ?: $file_path;
        $original_size = filesize($file_path);
        
        try {
            switch ($this->options['compression_service']) {
                case 'tinypng':
                    $result = $this->compress_with_tinypng($file_path, $output_path);
                    break;
                case 'imageoptim':
                    $result = $this->compress_with_imageoptim($file_path, $output_path);
                    break;
                default:
                    $result = $this->compress_with_php_native($file_path, $output_path);
                    break;
            }
            
            if ($result['success']) {
                $compressed_size = filesize($output_path);
                $savings = round((($original_size - $compressed_size) / $original_size) * 100, 1);
                
                return array(
                    'success' => true,
                    'file_path' => $output_path,
                    'original_size' => $original_size,
                    'compressed_size' => $compressed_size,
                    'savings_percent' => $savings,
                    'service_used' => $this->options['compression_service']
                );
            }
            
            return $result;
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => $e->getMessage(),
                'file_path' => $file_path
            );
        }
    }
    
    /**
     * PHP Native compression using GD or Imagick
     */
    private function compress_with_php_native($file_path, $output_path) {
        $mime_type = mime_content_type($file_path);
        $quality = $this->options['compression_quality'];
        
        switch ($mime_type) {
            case 'image/jpeg':
                return $this->compress_jpeg_native($file_path, $output_path, $quality);
            case 'image/png':
                return $this->compress_png_native($file_path, $output_path);
            case 'image/webp':
                return $this->compress_webp_native($file_path, $output_path, $quality);
            default:
                // Unsupported format, return original
                if ($file_path !== $output_path) {
                    copy($file_path, $output_path);
                }
                return array('success' => true);
        }
    }
    
    private function compress_jpeg_native($file_path, $output_path, $quality) {
        if (extension_loaded('imagick')) {
            $image = new Imagick($file_path);
            $image->setImageCompressionQuality($quality);
            $image->stripImage(); // Remove EXIF data
            $image->writeImage($output_path);
            $image->destroy();
        } elseif (extension_loaded('gd')) {
            $image = imagecreatefromjpeg($file_path);
            imagejpeg($image, $output_path, $quality);
            imagedestroy($image);
        } else {
            throw new Exception('No image processing extension available');
        }
        
        return array('success' => true);
    }
    
    private function compress_png_native($file_path, $output_path) {
        if (extension_loaded('imagick')) {
            $image = new Imagick($file_path);
            $image->setImageCompressionQuality(95);
            $image->stripImage();
            $image->writeImage($output_path);
            $image->destroy();
        } elseif (extension_loaded('gd')) {
            $image = imagecreatefrompng($file_path);
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagepng($image, $output_path, 9); // Max compression for PNG
            imagedestroy($image);
        } else {
            throw new Exception('No image processing extension available');
        }
        
        return array('success' => true);
    }
    
    private function compress_webp_native($file_path, $output_path, $quality) {
        if (extension_loaded('imagick')) {
            $image = new Imagick($file_path);
            $image->setImageCompressionQuality($quality);
            $image->writeImage($output_path);
            $image->destroy();
        } elseif (extension_loaded('gd') && function_exists('imagewebp')) {
            $image = imagecreatefromwebp($file_path);
            imagewebp($image, $output_path, $quality);
            imagedestroy($image);
        } else {
            throw new Exception('WebP support not available');
        }
        
        return array('success' => true);
    }
    
    /**
     * TinyPNG compression
     */
    private function compress_with_tinypng($file_path, $output_path) {
        if (empty($this->options['tinypng_api_key'])) {
            throw new Exception('TinyPNG API key not configured');
        }
        
        $api_key = $this->options['tinypng_api_key'];
        
        // Upload to TinyPNG
        $upload_response = wp_remote_post('https://api.tinify.com/shrink', array(
            'timeout' => 60,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode('api:' . $api_key),
                'Content-Type' => 'application/octet-stream'
            ),
            'body' => file_get_contents($file_path)
        ));
        
        if (is_wp_error($upload_response)) {
            throw new Exception('TinyPNG upload failed: ' . $upload_response->get_error_message());
        }
        
        $upload_body = json_decode(wp_remote_retrieve_body($upload_response), true);
        
        if (!isset($upload_body['output']['url'])) {
            throw new Exception('TinyPNG compression failed: ' . ($upload_body['message'] ?? 'Unknown error'));
        }
        
        // Download compressed image
        $download_response = wp_remote_get($upload_body['output']['url'], array('timeout' => 60));
        
        if (is_wp_error($download_response)) {
            throw new Exception('TinyPNG download failed: ' . $download_response->get_error_message());
        }
        
        file_put_contents($output_path, wp_remote_retrieve_body($download_response));
        
        return array('success' => true);
    }
    
    /**
     * ImageOptim compression
     */
    private function compress_with_imageoptim($file_path, $output_path) {
        // Free service, no API key needed
        $response = wp_remote_post('https://im2.io/api/upload', array(
            'timeout' => 60,
            'body' => array(
                'file' => new CURLFile($file_path)
            )
        ));
        
        if (is_wp_error($response)) {
            throw new Exception('ImageOptim upload failed: ' . $response->get_error_message());
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!isset($body['dest'])) {
            throw new Exception('ImageOptim compression failed');
        }
        
        // Download compressed image
        $download_response = wp_remote_get($body['dest'], array('timeout' => 60));
        
        if (is_wp_error($download_response)) {
            throw new Exception('ImageOptim download failed');
        }
        
        file_put_contents($output_path, wp_remote_retrieve_body($download_response));
        
        return array('success' => true);
    }
}