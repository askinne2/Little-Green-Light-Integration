# Migration Guide: Integrate-LGL v1.0.0 → v2.0.0

**Migration Date:** November 9, 2024  
**Environment:** ui-hybrid LocalWP by Flywheel

## Overview

This document outlines the changes between the legacy Integrate-LGL plugin (v1.0.0) and the modernized version (v2.0.0), and provides guidance for migrating between versions.

## Architecture Changes

### v1.0.0 (Legacy)
- **Structure:** Procedural PHP in single `includes/` directory
- **File Organization:** Flat file structure
- **Naming:** Functions prefixed with `lgl_api_`
- **Dependencies:** Loaded via Carbon Fields and PHP Debug
- **Autoloading:** Basic Composer autoloader

### v2.0.0 (Modernized)
- **Structure:** Object-oriented PHP with namespaces
- **File Organization:** PSR-4 autoloading with `src/` directory
- **Namespace:** `UpstateInternational\LGL\*`
- **Dependencies:** Modern dependency injection container
- **Autoloading:** PSR-4 compliant with service container

## Directory Structure Comparison

### Legacy (v1.0.0)
```
Integrate-LGL/
├── includes/
│   ├── lgl-api-settings.php
│   ├── lgl-connections.php
│   ├── lgl-constituents.php
│   ├── lgl-helper.php
│   ├── lgl-payments.php
│   ├── lgl-relations-manager.php
│   ├── lgl-wp-users.php
│   └── ui_memberships/
├── lgl-api.php (main file)
├── form-emails/
├── docs/
└── vendor/
```

### Modern (v2.0.0)
```
Integrate-LGL/
├── src/
│   ├── Admin/          # Dashboard widgets, settings pages
│   ├── Core/           # Plugin core, service container
│   ├── Email/          # Email management, blocking
│   ├── JetFormBuilder/ # Form builder actions
│   ├── LGL/            # LGL API integration
│   ├── Memberships/    # Membership management
│   ├── Shortcodes/     # WordPress shortcodes
│   └── WooCommerce/    # WooCommerce integration
├── includes/           # Legacy compatibility layer
├── lgl-api.php         # Main plugin file
├── form-emails/        # Email templates
├── docs/               # Documentation
└── vendor/             # Composer dependencies
```

## Breaking Changes

### 1. Function Names & Classes

**Old (v1.0.0):**
```php
// Procedural functions
lgl_api_connect();
lgl_get_constituent();
```

**New (v2.0.0):**
```php
// Namespaced classes (backward compatible layer exists)
use UpstateInternational\LGL\LGL\Connection;
use UpstateInternational\LGL\LGL\Constituents;

$connection = Connection::getInstance();
$constituents = Constituents::getInstance();
```

**Note:** Legacy function wrappers are provided for backward compatibility.

### 2. Plugin Constant

**Old:** No version constant  
**New:** `LGL_PLUGIN_VERSION` constant defined

This is used by the theme to check if the plugin is active:
```php
if (!defined('LGL_PLUGIN_VERSION')) {
    // Plugin not active - show admin notice
}
```

### 3. Settings Storage

**Old:** Various scattered option names  
**New:** Organized under unified settings system

Settings are now managed through `SettingsHandler` class with better validation and sanitization.

### 4. Email Functionality

**Old:** Basic email sending  
**New:** Advanced email management system

- Email blocking rules
- White/blacklist management
- Test email functionality
- Admin settings page
- Detailed logging

### 5. Dashboard Widgets

**Old:** Simple widgets in theme  
**New:** Enhanced widgets in plugin

- Better error handling
- Improved UI
- More data visualization
- Admin settings integration

## New Features in v2.0.0

### 1. Dependency Injection Container
Modern service container for managing dependencies:
```php
$container = ServiceContainer::getInstance();
$emailManager = $container->get('email.daily_manager');
```

### 2. Email Blocking System
- Block emails by domain, email, or user ID
- White/blacklist management
- Test mode for development
- Admin UI for configuration

### 3. Enhanced Admin Interface
- Consolidated admin menu
- Settings pages for all features
- Testing utilities
- Better documentation

### 4. Improved Error Handling
- Comprehensive logging
- Better error messages
- Debug mode support
- Exception handling

### 5. Modern Code Standards
- PSR-4 autoloading
- Type declarations
- Namespaces
- DocBlocks
- SOLID principles

### 6. Membership Management
Complete rewrite with:
- Renewal strategy patterns
- Notification system
- User management
- Cron scheduling
- Migration utilities

## Migration Steps

### Step 1: Backup Current Plugin
```bash
cd /path/to/plugins/
mv Integrate-LGL Integrate-LGL-LEGACY-v1-backup
```

### Step 2: Install v2.0.0
```bash
cp -R /path/to/new/Integrate-LGL ./
```

### Step 3: Deactivate Old Plugin
In WordPress Admin:
1. Go to Plugins
2. Deactivate "Little Green Light API" (v1.0.0)

### Step 4: Activate New Plugin
1. Refresh Plugins page
2. Activate "Integrate LGL" (v2.0.0)

### Step 5: Reconfigure Settings
1. Go to LGL Settings in WordPress admin
2. Re-enter API credentials
3. Configure email recipients
4. Set up email blocking rules (if needed)
5. Test functionality

### Step 6: Verify Integration
- [ ] LGL API connection works
- [ ] Dashboard widgets display
- [ ] Daily emails are scheduled
- [ ] WooCommerce integration works
- [ ] JetFormBuilder actions function
- [ ] No PHP errors in logs

## Settings Migration

Most settings should migrate automatically, but verify these:

### API Settings
- **LGL API Key:** Should carry over
- **Account ID:** Should carry over
- **Test Mode:** Review and configure

### Email Settings
- **Recipients:** Reconfigure in new settings page
- **Schedule:** Verify cron job is scheduled
- **Blocking Rules:** Configure if needed

### WooCommerce Settings
- **Subscription Settings:** Review configuration
- **Order Processing:** Test checkout flow

## Compatibility

### Theme Compatibility
The modernized plugin includes a compatibility layer that maintains support for legacy function calls. Most theme code should work without changes.

### WordPress Version
- Minimum: 5.8
- Tested: 6.x

### PHP Version
- Minimum: 7.4
- Recommended: 8.0+

### Dependencies
- WooCommerce: 5.0+
- WooCommerce Subscriptions: 3.0+
- JetFormBuilder: Latest
- Elementor: Latest

## Troubleshooting

### Plugin Not Activating
1. Check PHP version (must be 7.4+)
2. Verify Composer autoloader exists
3. Check file permissions
4. Review error logs

### Settings Not Saving
1. Verify database write permissions
2. Check for JavaScript errors
3. Clear WordPress cache
3. Disable conflicting plugins

### API Connection Failing
1. Verify API credentials
2. Check firewall/proxy settings
3. Test direct API access
4. Review LGL API status

### Dashboard Widgets Not Showing
1. Verify plugin is activated
2. Check user permissions
3. Clear WordPress cache
4. Check for JavaScript errors

## Rollback Procedure

If you need to rollback to v1.0.0:

1. Deactivate v2.0.0 plugin
2. Rename backup directory:
   ```bash
   mv Integrate-LGL Integrate-LGL-v2-backup
   mv Integrate-LGL-LEGACY-v1-backup Integrate-LGL
   ```
3. Activate legacy plugin
4. Restore theme files from `_deprecated/` if needed

## Support & Resources

### Documentation
- See `README.md` for overview
- See `CHANGELOG.md` for version history
- See `docs/` directory for technical documentation

### Testing
- See theme's `TESTING-CHECKLIST.md`
- See theme's `CONFIGURATION-GUIDE.md`

### Logging
Error logs are written to:
- `src/logs/lgl-api.log`
- WordPress debug log (if enabled)

### Getting Help
1. Check error logs
2. Review documentation
3. Test with debug mode enabled
4. Contact: andrew@21adsmedia.com

## Best Practices

### Development Environment
- Use LocalWP or similar for testing
- Enable WordPress debug mode
- Monitor error logs
- Test all functionality before production

### Production Deployment
- Test thoroughly in staging first
- Backup database before deployment
- Deploy during low-traffic period
- Monitor for 48 hours after deployment
- Keep legacy backup for 30 days

### Ongoing Maintenance
- Keep plugin updated
- Monitor error logs weekly
- Test critical features monthly
- Document any custom modifications

## Version History

- **v1.0.0** (Legacy): Original procedural implementation
- **v2.0.0** (Current): Modern OOP implementation with DI container

## Credits

**Original Development:** Andrew Skinner  
**Modernization:** November 2024  
**Company:** 21 Ads Media

---

**Last Updated:** November 9, 2024  
**Next Review:** After production deployment (December 2024)

