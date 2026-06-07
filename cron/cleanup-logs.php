<?php

/**
 * Log Cleanup Cron Job
 * Removes old log files to prevent disk space issues
 * Run weekly: 0 3 * * 0
 */

$logPath = __DIR__ . '/../logs';
$maxAge = 30; // days
$maxSize = 100 * 1024 * 1024; // 100MB

if (!is_dir($logPath)) {
    echo "Log directory not found\n";
    exit(0);
}

$files = glob($logPath . '/*.log');
$deleted = 0;
$totalSize = 0;

foreach ($files as $file) {
    $fileAge = (time() - filemtime($file)) / 86400; // days
    $fileSize = filesize($file);
    
    // Delete if older than max age
    if ($fileAge > $maxAge) {
        unlink($file);
        $deleted++;
        echo "Deleted old log: " . basename($file) . " (age: " . round($fileAge) . " days)\n";
        continue;
    }
    
    // Check total size
    $totalSize += $fileSize;
}

// If total size exceeds limit, delete oldest files
if ($totalSize > $maxSize) {
    // Sort by modification time (oldest first)
    usort($files, function($a, $b) {
        return filemtime($a) - filemtime($b);
    });
    
    foreach ($files as $file) {
        if ($totalSize <= $maxSize) {
            break;
        }
        
        $fileSize = filesize($file);
        unlink($file);
        $totalSize -= $fileSize;
        $deleted++;
        echo "Deleted large log: " . basename($file) . "\n";
    }
}

// Compress old logs (optional)
$oldLogs = glob($logPath . '/*.log');
foreach ($oldLogs as $log) {
    $age = (time() - filemtime($log)) / 86400;
    if ($age > 7 && !file_exists($log . '.gz')) {
        // Compress log file
        $gz = gzopen($log . '.gz', 'w9');
        gzwrite($gz, file_get_contents($log));
        gzclose($gz);
        unlink($log);
        echo "Compressed log: " . basename($log) . "\n";
    }
}

echo "Log cleanup completed: {$deleted} files deleted\n";
