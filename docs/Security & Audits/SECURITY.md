# Security Considerations

This document outlines the security measures implemented in the Little Green Light Integration plugin and best practices for secure deployment.

## Security Features Implemented

### ✅ **1. Input Validation & Sanitization**

**Comprehensive Sanitization Utilities:**
All user input is sanitized using the `Utilities` class helper methods:

```php
use UpstateInternational\LGL\Core\Utilities;

// Sanitize $_POST data
$api_url = Utilities::getSanitizedPost('api_url', 'url', null);
$api_key = Utilities::getSanitizedPost('api_key', 'text', null);
$user_id = Utilities::getSanitizedPost('user_id', 'int', 0);
$price = Utilities::getSanitizedPost('price', 'float', 0.0);
$enabled = Utilities::getSanitizedPost('enabled', 'bool', false);
```

**Available Sanitization Types:**
- `text` - Uses `sanitize_text_field()` (default)
- `url` - Uses `sanitize_url()`
- `email` - Uses `sanitize_email()`
- `int` - Uses `absint()`
- `float` - Uses `floatval()`
- `bool` - Converts to boolean
- `array` - Sanitizes array values
- `textarea` - Uses `sanitize_textarea_field()`
- `key` - Uses `sanitize_key()`

**WordPress Settings API with Sanitization:**
All settings use proper sanitization callbacks via `SettingsManager`:

```php
'api_key' => [
    'type' => 'string',
    'required' => true,
    'validation' => 'min:32|max:255',
    'sanitize' => 'sanitize_text_field'
]
```

**Schema-Based Validation:**
The `SettingsManager` class provides comprehensive validation rules:
- `required` - Field must have a value
- `url` - Must be valid URL
- `email` - Must be valid email
- `integer` / `numeric` - Must be numeric
- `min:X` / `max:X` - Length/value constraints
- `in:a,b,c` - Must be one of allowed values

### ✅ **2. Output Escaping**

**All output is properly escaped:**
- `esc_html()` for text content
- `esc_attr()` for HTML attributes
- `esc_url()` for URLs
- `wp_json_encode()` for JSON data

**Example from View Components:**
```php
// src/Admin/Views/components/card.php
<div class="lgl-card <?php echo esc_attr($args['class']); ?>">
    <h3><?php echo esc_html($args['title']); ?></h3>
    <p><?php echo esc_html($args['content']); ?></p>
</div>
```

**ViewRenderer Component:**
All admin views use the `ViewRenderer` class which ensures:
- Variables are extracted into isolated scope
- All output goes through WordPress escaping functions
- 265+ instances of proper escaping across admin interface

### ✅ **3. AJAX Security**

**Nonce Verification:**
All AJAX handlers verify nonces to prevent CSRF attacks:

```php
// SettingsHandler.php
public function handleConnectionTest(): void {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'lgl_admin_nonce')) {
        wp_send_json_error('Nonce verification failed');
        return;
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
        return;
    }
    
    // Process request...
}
```

**Nonce Creation:**
Nonces are created in `AssetManager` and passed to JavaScript:

```php
// src/Admin/AssetManager.php
private function getLocalizedData(): array {
    return [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('lgl_admin_nonce'),
        // ...
    ];
}
```

**Capability Checks:**
All admin actions require appropriate capabilities:
- `manage_options` - For settings and configuration
- `edit_posts` - For some testing utilities (with additional checks)

### ✅ **4. SQL Injection Prevention**

**Uses WordPress Database API:**
All database queries use `$wpdb->prepare()` with placeholders:

```php
// src/Admin/SettingsManager.php
$verify = $wpdb->get_var($wpdb->prepare(
    "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
    self::OPTION_NAME
));
```

**No Direct SQL Queries:**
- Modern code uses WordPress APIs (`get_user_meta`, `get_post_meta`, `WP_Query`)
- All custom queries use prepared statements
- User input is sanitized before any database operations

**Example from CacheManager:**
```php
$transient_count = $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('_transient_lgl_') . '%'
    )
);
```

### ✅ **5. Direct File Access Protection**

**All PHP files check for WordPress context:**
```php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
```

**Uninstall script verification:**
```php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
```

### ✅ **6. API Key Storage**

**Secure Storage:**
- API keys stored in WordPress options table
- Never hardcoded in plugin files
- Accessed via `SettingsManager` or `ApiSettings` classes
- Can be stored in environment variables (recommended for production)

**Environment Variable Support:**
```php
// Recommended production setup in wp-config.php
define('LGL_API_URL', getenv('LGL_API_URL'));
define('LGL_API_KEY', getenv('LGL_API_KEY'));
```

**Access Control:**
```php
// Only administrators can view/edit API credentials
if (!current_user_can('manage_options')) {
    wp_die('Insufficient permissions');
}
```

### ✅ **7. Rate Limiting**

**LGL API Rate Limits:**
- Maximum: 300 API calls per 5 minutes (60 calls/minute)
- Plugin includes `RateLimiter` class to track and enforce limits
- Automatic delay mechanism when approaching limit
- Admin notifications when rate limit is reached

**Implementation:**
```php
// src/LGL/RateLimiter.php
public function canMakeRequest(): bool {
    $requests = $this->getRecentRequests();
    return count($requests) < self::RATE_LIMIT;
}
```

### ✅ **8. Error Handling & Logging**

**Comprehensive Error Handling:**
- Try-catch blocks around all external API calls
- Graceful fallbacks for API failures
- User-friendly error messages (no sensitive data exposed)

**Secure Logging:**
```php
// Logs only when debug mode enabled
Helper::getInstance()->debug('LGL API Error: ' . $error_message);

// Production logs don't expose sensitive data
// API keys, passwords, etc. are never logged
```

**Debug Mode Control:**
- Debug mode controlled via admin settings
- Logs written to `src/logs/lgl-api.log`
- Log files protected by `.htaccess` (not web-accessible)

### ✅ **9. Authentication & Authorization**

**WordPress User Authentication:**
- All admin actions check `is_user_logged_in()`
- Capability checks for all privileged operations
- No functionality exposed to non-authenticated users

**LGL API Authentication:**
- Bearer token authentication
- API key transmitted via secure headers
- HTTPS enforced for API communication

**Permission Hierarchy:**
```php
// Settings & Configuration
current_user_can('manage_options')

// Testing & Debugging (admin only)
current_user_can('manage_options') || current_user_can('edit_posts')

// Public shortcodes (authenticated users only)
is_user_logged_in()
```

### ✅ **10. Data Validation**

**JetFormBuilder Actions:**
All form actions validate required fields:

```php
public function validateRequest(array $request): bool {
    $required_fields = $this->getRequiredFields();
    
    foreach ($required_fields as $field) {
        if (!isset($request[$field]) || empty($request[$field])) {
            return false;
        }
    }
    
    // Validate user_id is numeric and positive
    if (!isset($request['user_id']) || !is_numeric($request['user_id'])) {
        return false;
    }
    
    return true;
}
```

**Order Processing:**
- Validates order exists and is accessible
- Checks product categories before processing
- Verifies user permissions for order actions
- Sanitizes all order meta data

## Security Best Practices for Deployment

### Production Environment Setup

1. **Enable HTTPS:**
   - All API communication must use HTTPS
   - WordPress admin must use SSL
   - Force SSL in `wp-config.php`:
   ```php
   define('FORCE_SSL_ADMIN', true);
   ```

2. **Secure API Credentials:**
   - Store in environment variables, not database
   - Use WordPress secrets in `wp-config.php`
   - Never commit credentials to version control

3. **Disable Debug Mode:**
   - Set `debug_mode` to false in production
   - Remove or protect log files
   - Set `WP_DEBUG` to false

4. **File Permissions:**
   - Plugin files: 644
   - Plugin directories: 755
   - wp-config.php: 600 (read/write for owner only)

5. **Regular Updates:**
   - Keep WordPress core updated
   - Update plugin dependencies via Composer
   - Monitor security advisories

### Development Environment

1. **Email Blocking:**
   - Plugin automatically detects local environment
   - Blocks all emails in development
   - Whitelist specific addresses if needed

2. **Debug Mode:**
   - Enable debug mode for development
   - Review logs regularly
   - Clear logs before committing

3. **Test Data:**
   - Use test API credentials
   - Never use production data in development
   - Clean up test users/orders regularly

## Security Audit Checklist

Before deploying to production, verify:

- [ ] All `$_POST` access uses `Utilities::getSanitizedPost()`
- [ ] All `$_GET` access uses `Utilities::getSanitizedGet()`
- [ ] All AJAX endpoints verify nonces
- [ ] All admin actions check capabilities
- [ ] All database queries use prepared statements
- [ ] All output is properly escaped
- [ ] API keys not hardcoded in files
- [ ] Debug mode disabled in production
- [ ] HTTPS enabled for all admin pages
- [ ] Error messages don't expose sensitive data
- [ ] File permissions are correct
- [ ] Log files are protected/removed

## Reporting Security Issues

If you discover a security vulnerability in this plugin:

1. **Do not** open a public GitHub issue
2. Email security concerns to: [your-security-email@example.com]
3. Include:
   - Description of the vulnerability
   - Steps to reproduce
   - Potential impact
   - Suggested fix (if available)

We will respond within 48 hours and work with you to resolve the issue before public disclosure.

---

**Last Updated:** November 17, 2025  
**Plugin Version:** 2.0.0+  
**Security Review:** Complete

