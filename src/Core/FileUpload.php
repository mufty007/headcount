<?php

namespace Headcount\Core;

use Headcount\Core\SecurityLogger;

/**
 * Secure File Upload Class
 * Handles secure file uploads with validation
 */
class FileUpload
{
    private $allowedTypes;
    private $maxSize;
    private $uploadPath;
    private $allowedExtensions;

    public function __construct($config = [])
    {
        $this->allowedTypes = $config['allowed_types'] ?? ['image/jpeg', 'image/png', 'image/gif', 'text/csv'];
        $this->maxSize = $config['max_size'] ?? 10485760; // 10MB
        $this->uploadPath = $config['upload_path'] ?? __DIR__ . '/../../uploads/';
        $this->allowedExtensions = $config['allowed_extensions'] ?? ['jpg', 'jpeg', 'png', 'gif', 'csv'];
        
        // Create upload directory if it doesn't exist
        if (!is_dir($this->uploadPath)) {
            mkdir($this->uploadPath, 0755, true);
        }
    }
    
    /**
     * Upload file with security checks
     */
    public function upload($file, $subdirectory = '')
    {
        // Validate file was uploaded
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            SecurityLogger::logFileUpload($file['name'] ?? 'unknown', false, 'Invalid upload');
            throw new \Exception('Invalid file upload');
        }
        
        // Validate file type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $this->allowedTypes)) {
            SecurityLogger::logFileUpload($file['name'], false, 'Invalid file type: ' . $mimeType);
            throw new \Exception('Invalid file type. Allowed types: ' . implode(', ', $this->allowedTypes));
        }
        
        // Validate file size
        if ($file['size'] > $this->maxSize) {
            SecurityLogger::logFileUpload($file['name'], false, 'File too large: ' . $file['size']);
            throw new \Exception('File too large. Maximum size: ' . ($this->maxSize / 1048576) . 'MB');
        }
        
        // Validate extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $this->allowedExtensions)) {
            SecurityLogger::logFileUpload($file['name'], false, 'Invalid extension: ' . $extension);
            throw new \Exception('Invalid file extension');
        }
        
        // Validate and sanitize subdirectory name to prevent path traversal
        if ($subdirectory) {
            // Remove any path traversal attempts
            $subdirectory = str_replace(['..', '/', '\\'], '', $subdirectory);
            
            // Validate subdirectory name (alphanumeric, underscore, hyphen only)
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $subdirectory)) {
                SecurityLogger::logFileUpload($file['name'], false, 'Invalid subdirectory name');
                throw new \Exception('Invalid subdirectory name');
            }
        }
        
        // Generate safe filename
        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = $this->uploadPath . ($subdirectory ? $subdirectory . '/' : '') . $filename;
        
        // Ensure subdirectory exists
        if ($subdirectory) {
            $subdirPath = $this->uploadPath . $subdirectory;
            
            // Create subdirectory FIRST if it doesn't exist
            if (!is_dir($subdirPath)) {
                mkdir($subdirPath, 0755, true);
            }
            
            // THEN validate with realpath (now that directory exists)
            $realUploadPath = realpath($this->uploadPath);
            $realSubdirPath = realpath($subdirPath);
            
            // Ensure subdirectory is within upload path
            if ($realSubdirPath === false || strpos($realSubdirPath, $realUploadPath) !== 0) {
                SecurityLogger::logFileUpload($file['name'], false, 'Path traversal attempt detected');
                throw new \Exception('Invalid file path');
            }
        }
        
        // Final validation: ensure destination is within upload path
        $realDestination = realpath(dirname($destination));
        $realUploadPath = realpath($this->uploadPath);
        if ($realDestination === false || strpos($realDestination, $realUploadPath) !== 0) {
            SecurityLogger::logFileUpload($file['name'], false, 'Path traversal attempt detected in destination');
            throw new \Exception('Invalid file path');
        }
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            SecurityLogger::logFileUpload($file['name'], false, 'Move failed');
            throw new \Exception('File upload failed');
        }
        
        // Optimize images if it's an image file
        if (in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
            self::optimizeImage($destination, $mimeType);
        }
        
        // Set secure permissions
        chmod($destination, 0644);
        
        SecurityLogger::logFileUpload($filename, true);
        
        return [
            'filename' => $filename,
            'original_name' => $file['name'],
            'size' => filesize($destination), // Use optimized size
            'type' => $mimeType,
            'path' => $destination
        ];
    }
    
    /**
     * Optimize image (resize and compress)
     * 
     * @param string $imagePath Path to image file
     * @param string $mimeType Image MIME type
     * @return bool Success
     */
    private static function optimizeImage($imagePath, $mimeType)
    {
        if (!function_exists('imagecreatefromjpeg') && !function_exists('imagecreatefrompng')) {
            return false; // GD extension not available
        }
        
        $maxWidth = 1920;
        $maxHeight = 1080;
        $quality = 85; // JPEG quality (1-100)
        
        try {
            // Get image dimensions
            $imageInfo = @getimagesize($imagePath);
            if ($imageInfo === false) {
                return false;
            }
            
            list($width, $height) = $imageInfo;
            
            // Only resize if image is larger than max dimensions
            if ($width <= $maxWidth && $height <= $maxHeight) {
                // Just compress without resizing
                return self::compressImage($imagePath, $mimeType, $quality);
            }
            
            // Calculate new dimensions maintaining aspect ratio
            $ratio = min($maxWidth / $width, $maxHeight / $height);
            $newWidth = (int)($width * $ratio);
            $newHeight = (int)($height * $ratio);
            
            // Create image resource
            $source = null;
            switch ($mimeType) {
                case 'image/jpeg':
                    $source = @imagecreatefromjpeg($imagePath);
                    break;
                case 'image/png':
                    $source = @imagecreatefrompng($imagePath);
                    break;
                case 'image/gif':
                    $source = @imagecreatefromgif($imagePath);
                    break;
                case 'image/webp':
                    if (function_exists('imagecreatefromwebp')) {
                        $source = @imagecreatefromwebp($imagePath);
                    }
                    break;
            }
            
            if (!$source) {
                return false;
            }
            
            // Create new image
            $newImage = imagecreatetruecolor($newWidth, $newHeight);
            
            // Preserve transparency for PNG and GIF
            if ($mimeType === 'image/png' || $mimeType === 'image/gif') {
                imagealphablending($newImage, false);
                imagesavealpha($newImage, true);
                $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
                imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $transparent);
            }
            
            // Resize image
            imagecopyresampled($newImage, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            
            // Save optimized image
            $result = false;
            switch ($mimeType) {
                case 'image/jpeg':
                    $result = imagejpeg($newImage, $imagePath, $quality);
                    break;
                case 'image/png':
                    // PNG compression level (0-9, 9 is highest compression)
                    $result = imagepng($newImage, $imagePath, 6);
                    break;
                case 'image/gif':
                    $result = imagegif($newImage, $imagePath);
                    break;
                case 'image/webp':
                    if (function_exists('imagewebp')) {
                        $result = imagewebp($newImage, $imagePath, $quality);
                    }
                    break;
            }
            
            // Clean up
            imagedestroy($source);
            imagedestroy($newImage);
            
            return $result !== false;
        } catch (\Exception $e) {
            error_log("Image optimization failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Compress image without resizing
     * 
     * @param string $imagePath Path to image file
     * @param string $mimeType Image MIME type
     * @param int $quality Compression quality
     * @return bool Success
     */
    private static function compressImage($imagePath, $mimeType, $quality = 85)
    {
        if (!function_exists('imagecreatefromjpeg')) {
            return false;
        }
        
        try {
            $source = null;
            switch ($mimeType) {
                case 'image/jpeg':
                    $source = @imagecreatefromjpeg($imagePath);
                    if ($source) {
                        imagejpeg($source, $imagePath, $quality);
                        imagedestroy($source);
                        return true;
                    }
                    break;
                case 'image/png':
                    $source = @imagecreatefrompng($imagePath);
                    if ($source) {
                        imagepng($source, $imagePath, 6);
                        imagedestroy($source);
                        return true;
                    }
                    break;
            }
            return false;
        } catch (\Exception $e) {
            error_log("Image compression failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Validate image file
     */
    public function validateImage($file)
    {
        $allowedImageTypes = ['image/jpeg', 'image/png', 'image/gif'];
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedImageTypes)) {
            return false;
        }
        
        // Additional validation: try to get image dimensions
        $imageInfo = @getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Delete uploaded file
     */
    public function delete($filename, $subdirectory = '')
    {
        // Validate subdirectory name
        if ($subdirectory) {
            $subdirectory = str_replace(['..', '/', '\\'], '', $subdirectory);
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $subdirectory)) {
                throw new \Exception('Invalid subdirectory name');
            }
        }
        
        $filePath = $this->uploadPath . ($subdirectory ? $subdirectory . '/' : '') . $filename;
        
        // Validate path is within upload directory
        $realFilePath = realpath($filePath);
        $realUploadPath = realpath($this->uploadPath);
        
        if ($realFilePath === false || strpos($realFilePath, $realUploadPath) !== 0) {
            throw new \Exception('Invalid file path');
        }
        
        if (file_exists($filePath)) {
            unlink($filePath);
            return true;
        }
        
        return false;
    }
}
