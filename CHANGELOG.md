# Changelog

All notable changes to the LGL Integration plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.1.0] - 2025-11-08

### Added - Settings Modernization
- **SettingsManager Service**: New unified settings management system with PSR-11 compliant interface
  - `SettingsManagerInterface` with 11 methods for complete settings lifecycle management
  - Schema-based validation with comprehensive rules for all setting types
  - Automatic Carbon Fields migration on first load
  - Settings export/import functionality
  - Connection testing with structured error reporting
  - Membership level import from LGL API
  - Built-in caching with 1-hour TTL

- **View Rendering System**: Component-based UI architecture
  - `ViewRenderer` class for template rendering
  - Global helper functions: `lgl_render_view()`, `lgl_partial()`, `lgl_view_exists()`
  - Reusable components: card, status-item, button, notice, table
  - Admin page layout template
  - Partial views for system-status, connection-test, and statistics

- **Asset Management**: Consolidated CSS and JavaScript
  - `AssetManager` class for centralized asset enqueuing
  - Consolidated admin styles in `assets/admin/css/admin-bundle.css`
  - Consolidated admin scripts in `assets/admin/js/admin-bundle.js`
  - Localized JavaScript data with translations and URLs
  - Version-based cache busting

- **Developer Tools**:
  - Backup script: `scripts/backup-before-settings-modernization.sh`
  - Automatic rollback script generation
  - Git branch snapshot creation
  - Settings export/import functionality

### Changed
- **ApiSettings**: Marked as `@deprecated 2.1.0` (use `SettingsManager` instead)
  - Now delegates to `SettingsManager` as first priority
  - Maintains backward compatibility with existing code
  - Fallback chain: Settings Manager → Settings Handler → Carbon Fields → WordPress options

- **SettingsHandler**: Refactored to delegate to `SettingsManager`
  - `getSettings()` now calls `SettingsManager::getAll()`
  - `updateSettings()` now calls `SettingsManager::update()` with validation
  - `handleConnectionTest()` now calls `SettingsManager::testConnection()`
  - Simplified code by removing duplicate logic

- **AdminMenuManager**: Injected with `SettingsManager`
  - Constructor signature updated (backward compatible)
  - Ready for incremental component adoption
  - AssetManager integration prepared

- **TestingHandler**: Injected with `SettingsManager`
  - Constructor signature updated (backward compatible)
  - Ready for incremental component adoption

- **ServiceContainer**: Enhanced registrations
  - Added `admin.settings_manager` service
  - Added `admin.asset_manager` service
  - Updated all admin service dependencies

- **Plugin Core**: Integrated new architecture
  - AssetManager initialized for admin pages
  - Helper functions loaded after Composer autoloader
  - Enhanced error handling and logging

### Improved
- **Settings Schema**: Complete data contract with 15 settings types
  - API configuration (url, key)
  - System settings (debug, test, log levels)
  - Performance settings (cache TTL, request limits)
  - Email settings (blocking, notifications, daily summaries)
  - Membership levels (complex array field with sub-schema)
  - Mapping configurations (funds, campaigns, payment types, relations)

- **Code Quality**:
  - PSR-4 autoloading compliance
  - PSR-11 service container compliance
  - Type hints on all new methods
  - Comprehensive DocBlocks with `@since`, `@deprecated`, `@param`, `@return` tags
  - Zero linter errors

- **Performance**:
  - Settings caching with smart invalidation
  - Reduced database queries via caching
  - Optimized autoloader generation
  - Consolidated asset loading

### Security
- Nonce verification in all AJAX handlers
- Capability checks for admin operations
- Input sanitization in settings forms
- XSS prevention in view rendering

### Developer Experience
- Global `lgl_get_container()` function for service access
- Fluent API for view rendering
- Comprehensive inline documentation
- Migration guides in planning documents
- Backup/restore workflows

### Backward Compatibility
- All existing `ApiSettings::getInstance()->getSetting()` calls work unchanged
- Legacy `LGL_API_Settings` class alias maintained
- WP option `lgl_integration_settings` unchanged
- Carbon Fields data auto-migrates
- Zero breaking changes to public API

### Documentation
- New: `docs/settings-inventory.md` - Current state analysis
- New: `docs/settings-architecture-design.md` - Target architecture
- New: `docs/settings-implementation-plan.md` - Implementation guide
- New: `docs/settings-ui-plan.md` - UI transformation strategy
- New: `docs/settings-rollout-plan.md` - Deployment and testing
- New: `docs/settings-overhaul-summary.md` - Executive summary
- New: `docs/SETTINGS-OVERHAUL-INDEX.md` - Documentation index
- New: `CHANGELOG.md` - This file
- Updated: `README.md` - Added SettingsManager examples
- Updated: `docs/lgl_optimization_checklist.md` - Phase 7 marked complete

### Infrastructure
- Composer autoloader regenerated with optimizations
- Git-friendly backup system
- Automated rollback capability
- 35-point regression test matrix defined

---

## [2.0.0] - 2024

### Added
- Modern plugin architecture with PSR-4 autoloading
- Service Container for dependency injection
- LGL API integration for constituent management
- WooCommerce membership order processing
- JetFormBuilder custom actions
- Email blocking for local development
- Comprehensive logging system

### Changed
- Migrated from procedural to object-oriented architecture
- Implemented singleton pattern for core services
- Modernized hook management
- Separated concerns into focused classes

---

## [1.x] - Legacy

Legacy versions before modern architecture implementation.

