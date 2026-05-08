# Disk Usage Sunburst - Developer Documentation

## Overview

Disk Usage Sunburst 2.0 is a modern WordPress plugin built with object-oriented PHP, following WordPress coding standards and best practices. This document provides comprehensive information for developers who want to extend, customize, or integrate with the plugin.

## Architecture

### Directory Structure

```
disk-usage-sunburst/
├── src/                          # Source code (PSR-4 autoloaded)
│   ├── Admin/                    # Admin interface components
│   │   ├── AdminInterface.php    # Main admin class
│   │   └── templates/            # Admin page templates
│   ├── Rest/                     # REST API controllers
│   │   └── RestController.php    # API endpoints
│   ├── Scanner/                  # File scanning logic
│   │   └── FileScanner.php       # Core scanning functionality
│   └── Plugin.php                # Main plugin orchestrator
├── assets/                       # Frontend assets
│   ├── css/admin.css            # Admin styles
│   ├── js/admin.js              # Admin JavaScript (D3.js)
│   └── js/d3.v7.min.js          # D3.js library
├── tests/                        # Test suite
│   ├── Unit/                     # Unit tests
│   └── Integration/              # Integration tests
├── languages/                    # Translation files
├── composer.json                 # Composer configuration
├── phpunit.xml                   # PHPUnit configuration
└── rbdusb-disk-usage-sunburst.php # Main plugin file
```

### Class Structure

The plugin follows a modular approach with clear separation of concerns:

- **`Plugin`**: Main orchestrator class (singleton)
- **`FileScanner`**: Handles file system scanning with performance optimizations
- **`AdminInterface`**: Manages WordPress admin integration
- **`RestController`**: Provides REST API endpoints

## REST API Endpoints

The plugin exposes several REST API endpoints under the `/wp-json/disk-usage/v1/` namespace:

### Authentication

All endpoints require proper WordPress authentication and the `update_core` capability (or `manage_network` for multisite).

### Available Endpoints

#### `GET /wp-json/disk-usage/v1/scan`

Scan a directory and return disk usage data.

**Parameters:**
- `path` (string): Path to scan (default: WordPress root)
- `use_cache` (boolean): Use cached results (default: true)
- `max_depth` (integer): Maximum scan depth (default: 50)
- `max_files` (integer): Maximum files to scan (default: 10000)

**Example Request:**
```bash
curl -X GET "https://yoursite.com/wp-json/disk-usage/v1/scan?path=/wp-content/uploads&max_files=5000" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Response:**
```json
{
  "success": true,
  "data": {
    "name": "uploads",
    "size": 1048576,
    "type": "directory",
    "children": [...],
    "metadata": {
      "scan_time": 2.34,
      "files_count": 1234,
      "directories_count": 56,
      "total_size": 1048576
    }
  }
}
```

#### `POST /wp-json/disk-usage/v1/scan`

Perform a scan with advanced options.

**Body:**
```json
{
  "path": "/path/to/scan",
  "options": {
    "max_depth": 30,
    "max_files": 5000,
    "memory_limit": "512M"
  }
}
```

#### `DELETE /wp-json/disk-usage/v1/cache`

Clear scan cache.

**Parameters:**
- `path` (string): Specific path to clear (optional)

#### `GET /wp-json/disk-usage/v1/stats`

Get scan statistics and system information.

#### `GET /wp-json/disk-usage/v1/config`

Get current plugin configuration.

#### `POST /wp-json/disk-usage/v1/config`

Update plugin configuration.

## Action Hooks

The plugin provides several action hooks for extensibility:

### `rbdusb_activated`

Fired when the plugin is activated.

```php
add_action('rbdusb_activated', function() {
    // Plugin activation logic
});
```

### `rbdusb_deactivated`

Fired when the plugin is deactivated.

```php
add_action('rbdusb_deactivated', function() {
    // Plugin deactivation logic
});
```

### `rbdusb_scan_completed`

Fired when a scan completes successfully.

```php
add_action('rbdusb_scan_completed', function($result, $stats) {
    // Process scan results
    error_log('Scan completed: ' . $stats['files_scanned'] . ' files');
}, 10, 2);
```

### `rbdusb_scan_error`

Fired when a scan encounters an error.

```php
add_action('rbdusb_scan_error', function($error_message, $path) {
    // Handle scan errors
    error_log("Scan error for {$path}: {$error_message}");
}, 10, 2);
```

## Filter Hooks

### `rbdusb_exclude_paths`

Filter paths to exclude from scanning.

```php
add_filter('rbdusb_exclude_paths', function($excludes, $path) {
    // Add custom excludes
    $excludes[] = 'custom-cache-dir';
    $excludes[] = 'temp-files';
    return $excludes;
}, 10, 2);
```

### `rbdusb_scan_results`

Filter scan results before returning.

```php
add_filter('rbdusb_scan_results', function($results) {
    // Modify scan results
    $results['custom_metadata'] = 'added by filter';
    return $results;
});
```

## Programmatic Usage

### Basic Scanning

```php
use RaidBoxes\DiskUsageSunburst\Scanner\FileScanner;

// Create scanner instance
$scanner = new FileScanner();

// Perform scan
$result = $scanner->scan('/path/to/scan', false); // false = don't use cache

if (is_wp_error($result)) {
    echo 'Error: ' . $result->get_error_message();
} else {
    echo 'Total size: ' . $result['metadata']['total_size'];
}
```

### Advanced Configuration

```php
use RaidBoxes\DiskUsageSunburst\Scanner\FileScanner;

// Custom configuration
$config = [
    'max_execution_time' => 300,
    'memory_limit' => '512M',
    'max_depth' => 25,
    'max_files' => 5000,
    'cache_duration' => 7200, // 2 hours
];

$scanner = new FileScanner($config);

// Update configuration later
$scanner->update_config(['max_files' => 10000]);
```

### Cache Management

```php
use RaidBoxes\DiskUsageSunburst\Scanner\FileScanner;

$scanner = new FileScanner();

// Clear all cache
$scanner->clear_cache();

// Clear cache for specific path
$scanner->clear_cache('/specific/path');

// Get cache key for debugging
$cache_key = $scanner->get_cache_key('/path');
```

## JavaScript Integration

### Extending the Admin Interface

The admin JavaScript is built with ES6+ and D3.js v7. You can extend it by adding your own scripts:

```javascript
// Wait for the plugin to initialize
jQuery(document).ready(function($) {
    // Check if our analyzer exists
    if (window.rbdusbAnalyzer) {
        // Add custom functionality
        window.rbdusbAnalyzer.on('scan-complete', function(data) {
            console.log('Custom handler:', data);
        });
    }
});
```

### Custom Visualizations

```javascript
// Create custom D3.js visualization
function createCustomChart(data) {
    const svg = d3.select('#my-custom-chart')
        .append('svg')
        .attr('width', 800)
        .attr('height', 600);
    
    // Use the scan data to create your visualization
    // data structure: { name, size, type, children: [...] }
}
```

## Performance Optimization

### Recommended Settings

For large WordPress installations, consider these optimizations:

```php
// In wp-config.php or plugin
ini_set('max_execution_time', 300);
ini_set('memory_limit', '512M');

// Plugin configuration
$config = [
    'max_execution_time' => 300,
    'memory_limit' => '512M',
    'max_depth' => 30,        // Reduce depth for very large sites
    'max_files' => 20000,     // Increase for comprehensive scans
    'cache_duration' => 3600, // Cache for 1 hour
];
```

### Chunked Processing

For extremely large installations, consider implementing chunked processing:

```php
add_filter('rbdusb_scan_results', function($results) {
    // Implement custom chunking logic
    if ($results['metadata']['files_count'] > 50000) {
        // Process in chunks
    }
    return $results;
});
```

## Security Considerations

### Capability Checks

Always verify user capabilities when extending the plugin:

```php
if (!current_user_can('update_core')) {
    wp_die('Insufficient permissions');
}

// For multisite
if (is_multisite() && !current_user_can('manage_network')) {
    wp_die('Network admin required');
}
```

### Path Validation

When accepting user input for paths:

```php
use RaidBoxes\DiskUsageSunburst\Scanner\FileScanner;

function validate_scan_path($path) {
    // Ensure path is within WordPress directory
    $real_path = realpath($path);
    $wp_path = realpath(ABSPATH);
    
    return $real_path && $wp_path && strpos($real_path, $wp_path) === 0;
}
```

### Nonce Verification

Always use nonces for AJAX requests:

```php
if (!wp_verify_nonce($_POST['nonce'], 'my_custom_action')) {
    wp_die('Security check failed');
}
```

## Testing

### Running Tests

```bash
# Install dependencies
composer install

# Run all tests
vendor/bin/phpunit

# Run specific test suite
vendor/bin/phpunit tests/Unit
vendor/bin/phpunit tests/Integration

# Generate coverage report
vendor/bin/phpunit --coverage-html tests/coverage
```

### Writing Tests

```php
use RaidBoxes\DiskUsageSunburst\Tests\TestCase;
use RaidBoxes\DiskUsageSunburst\Scanner\FileScanner;

class MyCustomTest extends TestCase {
    
    public function test_custom_functionality() {
        $scanner = new FileScanner();
        $result = $scanner->scan($this->createTestDirectory(), false);
        
        $this->assertNotInstanceOf(\WP_Error::class, $result);
        $this->assertScanResultStructure($result);
    }
}
```

## Debugging

### Enable Debug Mode

```php
// In wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);

// Plugin-specific debugging
define('RBDUSB_DEBUG', true);
```

### Debug Information

The plugin logs important information when debugging is enabled:

```php
// Check scan statistics
$scanner = new FileScanner();
$stats = $scanner->get_stats();
error_log('Scan stats: ' . print_r($stats, true));

// Monitor memory usage
error_log('Memory peak: ' . memory_get_peak_usage(true));
```

## Contributing

### Code Standards

- Follow WordPress Coding Standards
- Use PSR-4 autoloading
- Write comprehensive PHPUnit tests
- Document all public methods
- Use meaningful variable and function names

### Submitting Changes

1. Fork the repository
2. Create a feature branch
3. Write tests for new functionality
4. Ensure all tests pass
5. Submit a pull request

## Support

For technical support or feature requests:

- GitHub Issues: [Repository URL]
- Email: wp-dev@raidboxes.de
- Documentation: [Plugin Documentation URL]

## License

This plugin is licensed under GPL-2.0-or-later. See the LICENSE file for details.