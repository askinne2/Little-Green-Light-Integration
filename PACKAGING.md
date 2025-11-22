# Plugin Packaging Guide

## Quick Start

To package the Integrate-LGL plugin for production:

```bash
cd wp-content/plugins/Integrate-LGL
./package-plugin.sh
```

The script will create a zip file in the parent directory (`wp-content/plugins/`) with a timestamp.

## Prerequisites

Before packaging, ensure production dependencies are installed:

```bash
composer install --no-dev --optimize-autoloader
```

The packaging script will prompt you to run this if `vendor/` is missing.

## What Gets Included

✅ **Included:**
- Main plugin file (`lgl-api.php`)
- All PHP source files (`src/`, `includes/`)
- Assets (`assets/` - CSS, JS)
- Templates (`templates/`)
- Form email templates (`form-emails/`)
- Composer dependencies (`vendor/`) - **CRITICAL**
- Essential documentation (`README.md`, `CHANGELOG.md`)
- Configuration (`composer.json`)

❌ **Excluded:**
- Development files (`.git`, `.gitignore`, IDE files)
- Documentation (`docs/` folder)
- Test files (`test/`, `tests/`)
- Log files (`src/logs/`, `*.log`)
- Development scripts (`refresh-autoloader.sh`, `package-plugin.sh`, `debug.sh`)
- Migration documentation (`MIGRATION-FROM-V1.md`)
- Composer lock file (`composer.lock`)
- Environment files (`.env`, `API-key.php`, etc.)

## Output

The script creates a zip file named:
```
integrate-lgl-production-YYYYMMDD-HHMMSS.zip
```

Located in: `wp-content/plugins/`

## Installation

### Via WordPress Admin (Recommended)

1. Upload the zip file via **WordPress Admin → Plugins → Add New → Upload Plugin**
2. Click **Install Now**
3. Click **Activate Plugin**
4. Navigate to **Settings → Little Green Light Settings**
5. Configure your API key and settings

### Manual Installation

1. Extract the zip file to: `wp-content/plugins/Integrate-LGL/`
2. Ensure file permissions are correct (folders: 755, files: 644)
3. Activate the plugin via WordPress Admin
4. Configure settings

## Package Contents

The production package includes:

- **Core Files**: Main plugin file and all PHP source code
- **Dependencies**: All Composer packages (vendor/)
- **Assets**: CSS and JavaScript files
- **Templates**: Email and display templates
- **Form Emails**: HTML email templates for various events
- **Documentation**: README.md and CHANGELOG.md

## Verification

The packaging script automatically verifies:

- ✅ Main plugin file exists (`lgl-api.php`)
- ✅ Composer autoloader exists (`vendor/autoload.php`)
- ✅ Core plugin class exists (`src/Core/Plugin.php`)
- ✅ Compatibility shim exists (`includes/lgl-api-compat.php`)
- ✅ Essential directories are present

## Requirements

- `zip` command (usually pre-installed on macOS/Linux)
- `rsync` command (optional, script falls back to `cp` if unavailable)
- `composer` (for dependency installation)

## Troubleshooting

**Permission denied:**
```bash
chmod +x package-plugin.sh
```

**Missing vendor directory:**
```bash
composer install --no-dev --optimize-autoloader
```

**Missing zip command:**
- macOS: Pre-installed
- Linux: `sudo apt-get install zip` or `sudo yum install zip`

**Package too large:**
- Ensure `vendor/` contains only production dependencies
- Run `composer install --no-dev` to exclude dev dependencies
- Check for accidentally included log files or test data

## Production Checklist

Before packaging for production deployment:

- [ ] Run `composer install --no-dev --optimize-autoloader`
- [ ] Verify all tests pass (if applicable)
- [ ] Check that no sensitive data is included (API keys, tokens)
- [ ] Ensure log files are excluded
- [ ] Verify README.md is up to date
- [ ] Test the packaged plugin on a staging environment first

## File Size Expectations

A typical production package should be:
- **With vendor/**: ~2-5 MB (depending on dependencies)
- **Essential files only**: ~500 KB - 1 MB

If your package is significantly larger, check for:
- Unnecessary files in vendor/
- Log files or test data
- Development dependencies included

## Security Notes

⚠️ **Important**: The packaging script excludes sensitive files, but always verify:

- No `.env` files included
- No API keys hardcoded in PHP files
- No `composer.lock` with dev dependencies
- No log files with sensitive data

## Support

For issues with packaging or deployment, check:
- `README.md` - Plugin documentation
- `docs/Testing & Troubleshooting/TROUBLESHOOTING.md` - Troubleshooting guide
- `docs/Current Status/PRODUCTION-READINESS-STATUS.md` - Production readiness status

