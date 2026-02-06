#!/bin/bash

# MySQL Replication Application - Quick Setup Script

echo "=========================================="
echo "MySQL Replication Application Setup"
echo "=========================================="
echo ""

# Check if PHP is installed
if ! command -v php &> /dev/null; then
    echo "❌ PHP is not installed. Please install PHP 7.4 or higher."
    exit 1
fi

echo "✓ PHP is installed: $(php -v | head -n 1)"

# Check if Composer is installed
if ! command -v composer &> /dev/null; then
    echo "⚠️  Composer is not installed. Attempting to install dependencies manually..."
else
    echo "✓ Composer is installed"
    echo ""
    echo "Installing dependencies..."
    composer install
fi

echo ""

# Check if config file exists
if [ ! -f config.json ] && [ ! -f config.local.json ]; then
    echo "⚠️  No configuration file found."
    echo "Creating config.json from example..."
    cp config.example.json config.json
    echo "✓ Created config.json"
    echo ""
    echo "⚠️  IMPORTANT: Edit config.json with your database credentials before running!"
else
    echo "✓ Configuration file exists"
fi

echo ""
echo "=========================================="
echo "Setup Complete!"
echo "=========================================="
echo ""
echo "To run the application:"
echo "  php -S localhost:8080 -t public public/index.php"
echo ""
echo "Then open your browser to:"
echo "  http://localhost:8080"
echo ""
echo "To set up automated sync, add to crontab:"
echo "  */5 * * * * php $(pwd)/src/sync.php"
echo ""
echo "For more information, see README.md"
echo ""
