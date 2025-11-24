#!/bin/bash

# Package Integrate-LGL Plugin for Production
# Creates a clean zip file excluding development files
# Suitable for deployment to test-sandbox-website or production

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Get the plugin directory (where this script is located)
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_NAME="Integrate-LGL"
OUTPUT_DIR="$PLUGIN_DIR/../"
ZIP_NAME="integrate-lgl-production-$(date +%Y%m%d-%H%M%S).zip"
TEMP_DIR=$(mktemp -d)

echo -e "${GREEN}📦 Packaging Integrate-LGL Plugin for Production${NC}"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# Check if we're in the right directory
if [ ! -f "$PLUGIN_DIR/lgl-api.php" ]; then
    echo -e "${RED}❌ Error: lgl-api.php not found. Are you in the plugin directory?${NC}"
    exit 1
fi

echo -e "${YELLOW}📁 Plugin directory: $PLUGIN_DIR${NC}"
echo -e "${YELLOW}📦 Output: $OUTPUT_DIR$ZIP_NAME${NC}"
echo ""

# Check if vendor directory exists (critical for production)
if [ ! -d "$PLUGIN_DIR/vendor" ]; then
    echo -e "${RED}❌ Error: vendor/ directory not found!${NC}"
    echo -e "${YELLOW}💡 Run 'composer install --no-dev' before packaging${NC}"
    echo ""
    read -p "Do you want to run 'composer install --no-dev' now? (y/n) " -n 1 -r
    echo ""
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        echo -e "${BLUE}📥 Installing production dependencies...${NC}"
        cd "$PLUGIN_DIR"
        composer install --no-dev --optimize-autoloader
        echo -e "${GREEN}✓ Dependencies installed${NC}"
    else
        echo -e "${RED}❌ Cannot package without vendor/ directory. Exiting.${NC}"
        exit 1
    fi
fi

# Create temporary directory structure
TEMP_PLUGIN_DIR="$TEMP_DIR/$PLUGIN_NAME"
mkdir -p "$TEMP_PLUGIN_DIR"

echo -e "${GREEN}📋 Copying files...${NC}"

# Copy files using rsync with exclusions
rsync -a \
    --exclude='.git' \
    --exclude='.gitignore' \
    --exclude='.DS_Store' \
    --exclude='.idea' \
    --exclude='.vscode' \
    --exclude='*.sublime-*' \
    --exclude='*.swp' \
    --exclude='*.swo' \
    --exclude='*~' \
    --exclude='.env' \
    --exclude='.env.*' \
    --exclude='*.env' \
    --exclude='*.log' \
    --exclude='*.log.*' \
    --exclude='src/logs' \
    --exclude='logs' \
    --exclude='test' \
    --exclude='tests' \
    --exclude='docs' \
    --exclude='scripts' \
    --exclude='refresh-autoloader.sh' \
    --exclude='package-plugin.sh' \
    --exclude='debug.sh' \
    --exclude='*.zip' \
    --exclude='*.tar' \
    --exclude='*.tar.gz' \
    --exclude='MIGRATION-FROM-V1.md' \
    --exclude='composer.lock' \
    "$PLUGIN_DIR/" "$TEMP_PLUGIN_DIR/" 2>/dev/null || {
    echo -e "${YELLOW}⚠ rsync not available, using cp...${NC}"
    # Fallback: manual copy with exclusions
    cd "$PLUGIN_DIR"
    find . -type f \
        ! -path './.git/*' \
        ! -path './.gitignore' \
        ! -path './.DS_Store' \
        ! -path './.idea/*' \
        ! -path './.vscode/*' \
        ! -path './*.sublime-*' \
        ! -path './*.swp' \
        ! -path './*.swo' \
        ! -path './*~' \
        ! -path './.env*' \
        ! -path './*.env' \
        ! -path './*.log' \
        ! -path './*.log.*' \
        ! -path './src/logs/*' \
        ! -path './logs/*' \
        ! -path './test/*' \
        ! -path './tests/*' \
        ! -path './docs/*' \
        ! -path './scripts/*' \
        ! -path './refresh-autoloader.sh' \
        ! -path './package-plugin.sh' \
        ! -path './debug.sh' \
        ! -path './*.zip' \
        ! -path './*.tar' \
        ! -path './*.tar.gz' \
        ! -path './MIGRATION-FROM-V1.md' \
        ! -path './composer.lock' \
        -exec cp --parents {} "$TEMP_PLUGIN_DIR/" \;
}

# Verify essential files exist
echo ""
echo -e "${GREEN}🔍 Verifying essential files...${NC}"

ESSENTIAL_FILES=(
    "lgl-api.php"
    "composer.json"
    "vendor/autoload.php"
    "src/Core/Plugin.php"
    "includes/lgl-api-compat.php"
)

MISSING_FILES=()
for file in "${ESSENTIAL_FILES[@]}"; do
    if [ ! -f "$TEMP_PLUGIN_DIR/$file" ]; then
        MISSING_FILES+=("$file")
    else
        echo -e "  ✓ $file"
    fi
done

if [ ${#MISSING_FILES[@]} -gt 0 ]; then
    echo -e "${RED}❌ Missing essential files:${NC}"
    for file in "${MISSING_FILES[@]}"; do
        echo -e "  ${RED}✗ $file${NC}"
    done
    rm -rf "$TEMP_DIR"
    exit 1
fi

# Verify directory structure
echo ""
echo -e "${GREEN}🔍 Verifying directory structure...${NC}"

ESSENTIAL_DIRS=(
    "src"
    "includes"
    "assets"
    "templates"
    "form-emails"
    "vendor"
)

for dir in "${ESSENTIAL_DIRS[@]}"; do
    if [ -d "$TEMP_PLUGIN_DIR/$dir" ]; then
        echo -e "  ✓ $dir/"
    else
        echo -e "  ${YELLOW}⚠ $dir/ (optional)${NC}"
    fi
done

# Count files in package
FILE_COUNT=$(find "$TEMP_PLUGIN_DIR" -type f | wc -l | tr -d ' ')
DIR_COUNT=$(find "$TEMP_PLUGIN_DIR" -type d | wc -l | tr -d ' ')

echo ""
echo -e "${BLUE}📊 Package contents:${NC}"
echo -e "  Files: $FILE_COUNT"
echo -e "  Directories: $DIR_COUNT"

# Create zip file
echo ""
echo -e "${GREEN}📦 Creating zip archive...${NC}"
cd "$TEMP_DIR"
zip -r "$OUTPUT_DIR$ZIP_NAME" "$PLUGIN_NAME" -q
cd - > /dev/null

# Clean up temp directory
rm -rf "$TEMP_DIR"

# Get file size
FILE_SIZE=$(du -h "$OUTPUT_DIR$ZIP_NAME" | cut -f1)

echo ""
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}✅ Plugin packaged successfully!${NC}"
echo ""
echo -e "📦 Package: ${GREEN}$ZIP_NAME${NC}"
echo -e "📁 Location: ${GREEN}$OUTPUT_DIR${NC}"
echo -e "📊 Size: ${GREEN}$FILE_SIZE${NC}"
echo ""
echo -e "${YELLOW}💡 To install:${NC}"
echo -e "   1. Upload via WordPress Admin → Plugins → Add New → Upload Plugin"
echo -e "   2. Or extract to: ${BLUE}wp-content/plugins/Integrate-LGL/${NC}"
echo -e "   3. Activate the plugin"
echo -e "   4. Configure API settings in: ${BLUE}Settings → Little Green Light Settings${NC}"
echo ""
echo -e "${BLUE}📝 Package includes:${NC}"
echo -e "   ✓ All PHP source files (src/, includes/)"
echo -e "   ✓ Assets (CSS, JS)"
echo -e "   ✓ Templates and form emails"
echo -e "   ✓ Composer dependencies (vendor/)"
echo -e "   ✓ README.md and CHANGELOG.md"
echo ""
echo -e "${BLUE}📝 Package excludes:${NC}"
echo -e "   ✗ Documentation (docs/)"
echo -e "   ✗ Test files (test/, tests/)"
echo -e "   ✗ Log files (src/logs/)"
echo -e "   ✗ Development scripts"
echo -e "   ✗ Git files (.git/, .gitignore)"
echo ""





