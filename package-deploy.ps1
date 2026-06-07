# Headcount Deployment Package Script for Windows
# Creates a clean deployment package excluding development files

$ErrorActionPreference = "Stop"

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Headcount Deployment Package Creator" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Configuration
$packageName = "headcount-deploy-$(Get-Date -Format 'yyyyMMdd-HHmmss')"
$packageDir = ".\deploy\$packageName"
$zipFile = ".\deploy\$packageName.zip"

# Directories and files to exclude
$excludePatterns = @(
    "node_modules",
    ".git",
    ".vscode",
    ".idea",
    "*.swp",
    "*.swo",
    "*~",
    ".DS_Store",
    "Thumbs.db",
    "logs\*.log",
    "*.log",
    "config\config.php",
    "uploads\*",
    "!uploads\.gitkeep",
    "deploy",
    ".gitignore",
    "package-lock.json",
    "yarn.lock",
    "composer.lock",
    "phpunit.xml",
    "test*.php",
    "check_*.php",
    "diagnose*.php",
    "fix_*.php",
    "list_*.php",
    "remove_*.php",
    "restore_*.php",
    "run_*.php",
    "setup_*.php",
    "unlock_*.php",
    "update_*.php",
    "verify_*.php",
    "version.php",
    "columns.txt",
    "temp_*.txt",
    "test-routing.php",
    "cli_migrate.php"
)

Write-Host "Creating deployment package..." -ForegroundColor Yellow
Write-Host "Package name: $packageName" -ForegroundColor Gray
Write-Host ""

# Create deploy directory if it doesn't exist
if (-not (Test-Path ".\deploy")) {
    New-Item -ItemType Directory -Path ".\deploy" | Out-Null
}

# Create package directory
if (Test-Path $packageDir) {
    Remove-Item -Path $packageDir -Recurse -Force
}
New-Item -ItemType Directory -Path $packageDir | Out-Null

Write-Host "Copying files..." -ForegroundColor Yellow

# Get all files and directories
$allItems = Get-ChildItem -Path . -Recurse -Force

$copiedCount = 0
$skippedCount = 0

foreach ($item in $allItems) {
    $relativePath = $item.FullName.Substring((Resolve-Path .).Path.Length + 1)
    
    # Skip if in deploy directory
    if ($relativePath -like "deploy\*") {
        continue
    }
    
    # Check exclusion patterns
    $shouldExclude = $false
    foreach ($pattern in $excludePatterns) {
        if ($pattern -like "!*") {
            # Inclusion pattern (negation)
            continue
        }
        if ($relativePath -like "*\$pattern" -or $relativePath -like $pattern) {
            $shouldExclude = $true
            break
        }
    }
    
    if ($shouldExclude) {
        $skippedCount++
        continue
    }
    
    # Create destination path
    $destPath = Join-Path $packageDir $relativePath
    
    # Create parent directory if needed
    $parentDir = Split-Path $destPath -Parent
    if (-not (Test-Path $parentDir)) {
        New-Item -ItemType Directory -Path $parentDir -Force | Out-Null
    }
    
    # Copy file or directory
    if ($item.PSIsContainer) {
        if (-not (Test-Path $destPath)) {
            New-Item -ItemType Directory -Path $destPath -Force | Out-Null
        }
    } else {
        Copy-Item -Path $item.FullName -Destination $destPath -Force
        $copiedCount++
    }
}

Write-Host ""
Write-Host "Files copied: $copiedCount" -ForegroundColor Green
Write-Host "Files skipped: $skippedCount" -ForegroundColor Gray
Write-Host ""

# Create empty directories that should exist
Write-Host "Creating required directories..." -ForegroundColor Yellow
$requiredDirs = @(
    "logs",
    "uploads",
    "uploads\event-banners",
    "uploads\program-banners"
)

foreach ($dir in $requiredDirs) {
    $dirPath = Join-Path $packageDir $dir
    if (-not (Test-Path $dirPath)) {
        New-Item -ItemType Directory -Path $dirPath -Force | Out-Null
        Write-Host "  Created: $dir" -ForegroundColor Gray
    }
}

# Create .htaccess for uploads directory
$uploadsHtaccess = Join-Path $packageDir "uploads\.htaccess"
Set-Content -Path $uploadsHtaccess -Value "# Prevent direct access to uploads`nDeny from all"

# Copy config sample
Write-Host ""
Write-Host "Preparing configuration..." -ForegroundColor Yellow
if (Test-Path "config\config-sample.php") {
    Copy-Item -Path "config\config-sample.php" -Destination (Join-Path $packageDir "config\config-sample.php") -Force
    Write-Host "  Config sample included" -ForegroundColor Gray
}

# Create deployment README
$readmeContent = @"
# Headcount Deployment Package

## Installation Instructions

1. Upload all files to your subdomain root directory
2. Set file permissions:
   - Directories: 755
   - Files: 644
   - config/config.php: 600 (after creating it)
3. Copy config/config-sample.php to config/config.php
4. Update config/config.php with your settings
5. Create database and import schema
6. Run installation wizard at: https://your-subdomain.com/install/

## Important Notes

- Make sure to set proper file permissions
- Configure database connection in config/config.php
- Update app.url in config.php to match your subdomain
- Ensure .htaccess files are uploaded
- Create empty logs/ and uploads/ directories if needed

For detailed instructions, see old-plans/SUBDOMAIN_DEPLOYMENT.md
"@

Set-Content -Path (Join-Path $packageDir "DEPLOYMENT_README.txt") -Value $readmeContent

Write-Host ""
Write-Host "Creating ZIP archive..." -ForegroundColor Yellow

# Create ZIP file
if (Test-Path $zipFile) {
    Remove-Item -Path $zipFile -Force
}

Add-Type -AssemblyName System.IO.Compression.FileSystem
[System.IO.Compression.ZipFile]::CreateFromDirectory($packageDir, $zipFile)

$zipSize = (Get-Item $zipFile).Length / 1MB
Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host "Package created successfully!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
Write-Host ""
Write-Host "Package location: $zipFile" -ForegroundColor Cyan
Write-Host "Package size: $([math]::Round($zipSize, 2)) MB" -ForegroundColor Cyan
Write-Host ""
Write-Host "Next steps:" -ForegroundColor Yellow
Write-Host "1. Extract the ZIP file" -ForegroundColor White
Write-Host "2. Upload all files to your subdomain" -ForegroundColor White
Write-Host "3. Follow old-plans/SUBDOMAIN_DEPLOYMENT.md guide" -ForegroundColor White
Write-Host ""

# Ask if user wants to keep the extracted folder
$response = Read-Host "Keep extracted folder? (Y/N)"
if ($response -ne "Y" -and $response -ne "y") {
    Remove-Item -Path $packageDir -Recurse -Force
    Write-Host "Extracted folder removed." -ForegroundColor Gray
} else {
    Write-Host "Extracted folder kept at: $packageDir" -ForegroundColor Gray
}
