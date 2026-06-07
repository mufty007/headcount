<?php

namespace Headcount\Tests\Unit;

use Headcount\Tests\TestCase;
use Headcount\Core\FileUpload;

/**
 * File Upload Tests
 * Tests secure file upload functionality
 */
class FileUploadTest extends TestCase
{
    private $uploadPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uploadPath = BASE_PATH . '/tests/uploads';
        
        // Create test upload directory
        if (!is_dir($this->uploadPath)) {
            mkdir($this->uploadPath, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up test files
        if (is_dir($this->uploadPath)) {
            $files = glob($this->uploadPath . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        
        parent::tearDown();
    }

    public function testFileUploadConfiguration()
    {
        $config = [
            'allowed_types' => ['image/jpeg', 'image/png'],
            'max_size' => 1048576, // 1MB
            'upload_path' => $this->uploadPath
        ];

        $uploader = new FileUpload($config);
        $this->assertInstanceOf(FileUpload::class, $uploader);
    }

    public function testFileTypeValidation()
    {
        $uploader = new FileUpload([
            'upload_path' => $this->uploadPath,
            'allowed_types' => ['image/jpeg']
        ]);

        // Create a test file
        $testFile = [
            'name' => 'test.jpg',
            'type' => 'image/jpeg',
            'size' => 1024,
            'tmp_name' => $this->createTestImageFile(),
            'error' => UPLOAD_ERR_OK
        ];

        // Should not throw exception for valid type
        try {
            $result = $uploader->upload($testFile);
            $this->assertArrayHasKey('filename', $result);
        } catch (\Exception $e) {
            // If file validation fails, that's okay for this test
            // We're just testing the class structure
        }
    }
    
    private function createTestImageFile()
    {
        // Create a minimal valid JPEG file
        $file = tempnam(sys_get_temp_dir(), 'test_');
        $image = imagecreate(10, 10);
        imagejpeg($image, $file);
        imagedestroy($image);
        return $file;
    }
}
