#!/bin/bash

# LGL Plugin Autoloader Refresh Script
# Run this whenever you add new classes to the src/ directory

echo "🔄 Refreshing LGL Plugin Autoloader..."
echo "=================================="

# Check if composer is available
if ! command -v composer &> /dev/null; then
    echo "❌ Composer not found. Please install Composer first."
    exit 1
fi

# Check if composer.json exists
if [ ! -f "composer.json" ]; then
    echo "❌ composer.json not found. Make sure you're in the plugin directory."
    exit 1
fi

# Regenerate autoloader with optimization
echo "📦 Generating optimized autoloader..."
composer dump-autoload -o

# Check if successful
if [ $? -eq 0 ]; then
    echo "✅ Autoloader refreshed successfully!"
    echo "🚀 Plugin ready with updated classes."
    
    # Count classes in autoloader
    CLASS_COUNT=$(grep -c "'" vendor/composer/autoload_classmap.php 2>/dev/null || echo "unknown")
    echo "📊 Total classes in autoloader: $CLASS_COUNT"
else
    echo "❌ Failed to refresh autoloader. Check for errors above."
    exit 1
fi

echo "=================================="
echo "✨ Done! Your plugin is ready to use."












