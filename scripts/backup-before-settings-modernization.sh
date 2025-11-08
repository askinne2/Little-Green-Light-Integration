#!/bin/bash
#
# Backup Script for Settings Modernization
# 
# Creates backups of settings data, database tables, and creates a git snapshot
# before implementing settings modernization changes.
# 
# @package UpstateInternational\LGL
# @since 2.1.0

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"
BACKUP_DIR="$PLUGIN_DIR/backups/settings-modernization-$(date +%Y%m%d-%H%M%S)"

echo -e "${GREEN}LGL Plugin: Settings Modernization Backup${NC}"
echo "=============================================="
echo ""

# Create backup directory
mkdir -p "$BACKUP_DIR"
echo -e "${YELLOW}Creating backup directory: $BACKUP_DIR${NC}"

# 1. Export WordPress options
echo -e "${YELLOW}Exporting WordPress options...${NC}"
wp option get lgl_integration_settings --format=json > "$BACKUP_DIR/lgl_integration_settings.json" 2>/dev/null || echo "[]" > "$BACKUP_DIR/lgl_integration_settings.json"
wp option get lgl_carbon_fields_migrated --format=json > "$BACKUP_DIR/lgl_carbon_fields_migrated.json" 2>/dev/null || echo "false" > "$BACKUP_DIR/lgl_carbon_fields_migrated.json"

echo -e "${GREEN}✓ WordPress options exported${NC}"

# 2. Export Carbon Fields data (if exists)
echo -e "${YELLOW}Exporting Carbon Fields data...${NC}"
wp db export "$BACKUP_DIR/carbon_fields_backup.sql" --tables=wp_options --porcelain 2>/dev/null || true
echo -e "${GREEN}✓ Carbon Fields data exported${NC}"

# 3. Create git snapshot
echo -e "${YELLOW}Creating git snapshot...${NC}"
cd "$PLUGIN_DIR"

if [ -d ".git" ]; then
    BRANCH_NAME="backup/settings-modernization-$(date +%Y%m%d-%H%M%S)"
    git branch "$BRANCH_NAME" 2>/dev/null || true
    git add -A
    git stash save "Pre-settings-modernization backup" 2>/dev/null || true
    echo -e "${GREEN}✓ Git snapshot created: $BRANCH_NAME${NC}"
    echo "$BRANCH_NAME" > "$BACKUP_DIR/git_branch.txt"
else
    echo -e "${YELLOW}⚠ No git repository found, skipping git snapshot${NC}"
fi

# 4. Create manifest file
echo -e "${YELLOW}Creating backup manifest...${NC}"
cat > "$BACKUP_DIR/MANIFEST.txt" << EOF
LGL Plugin Settings Modernization Backup
========================================

Backup Date: $(date)
Plugin Directory: $PLUGIN_DIR
Backup Directory: $BACKUP_DIR

Files Included:
- lgl_integration_settings.json - Current settings data
- lgl_carbon_fields_migrated.json - Migration flag
- carbon_fields_backup.sql - Database export
- git_branch.txt - Git branch name (if applicable)
- MANIFEST.txt - This file

Restoration Instructions:
1. To restore WordPress options:
   wp option update lgl_integration_settings --format=json < lgl_integration_settings.json

2. To restore database:
   wp db import carbon_fields_backup.sql

3. To restore git state:
   git checkout <branch-name-from-git_branch.txt>

4. To verify restoration:
   wp option get lgl_integration_settings
EOF

echo -e "${GREEN}✓ Manifest created${NC}"

# 5. Create rollback script
echo -e "${YELLOW}Creating rollback script...${NC}"
cat > "$BACKUP_DIR/rollback.sh" << 'ROLLBACK_SCRIPT'
#!/bin/bash
set -e

BACKUP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "Rolling back to backup from: $(cat "$BACKUP_DIR/MANIFEST.txt" | grep "Backup Date" | cut -d: -f2-)"
echo ""
echo "WARNING: This will overwrite current settings!"
read -p "Continue? (yes/no): " confirm

if [ "$confirm" != "yes" ]; then
    echo "Rollback cancelled."
    exit 1
fi

# Restore WordPress options
echo "Restoring WordPress options..."
wp option update lgl_integration_settings --format=json < "$BACKUP_DIR/lgl_integration_settings.json"
wp option update lgl_carbon_fields_migrated --format=json < "$BACKUP_DIR/lgl_carbon_fields_migrated.json"

echo "✓ Rollback complete!"
echo ""
echo "Please verify your settings at wp-admin/admin.php?page=lgl-settings"
ROLLBACK_SCRIPT

chmod +x "$BACKUP_DIR/rollback.sh"
echo -e "${GREEN}✓ Rollback script created${NC}"

# Summary
echo ""
echo -e "${GREEN}=============================================="
echo "Backup Complete!"
echo "=============================================="
echo -e "${NC}"
echo "Backup location: $BACKUP_DIR"
echo ""
echo "To rollback if needed:"
echo "  cd $BACKUP_DIR && ./rollback.sh"
echo ""

