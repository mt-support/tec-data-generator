#!/bin/bash
# Package the TEC Data Generator plugin into a distributable ZIP file

set -e

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_NAME="tec-data-generator"
VERSION=$(grep "define( 'TEC_DATA_GENERATOR_VERSION'" "$PLUGIN_DIR/$PLUGIN_NAME.php" | grep -o "'[^']*'" | tail -1 | tr -d "'")
OUTPUT_FILE="$PLUGIN_DIR/${PLUGIN_NAME}-${VERSION}.zip"

echo "Packaging $PLUGIN_NAME v$VERSION..."
cd "$(dirname "$PLUGIN_DIR")"

# Create zip, explicitly excluding dev files and directories.
# Any dot-folder (e.g. .cursor, .opencode, .git) is excluded by the generic
# ".*" patterns, so new tooling directories never leak into the QA zip.
zip -r "$OUTPUT_FILE" "$PLUGIN_NAME" \
  -x "$PLUGIN_NAME/.*/*" \
  -x "$PLUGIN_NAME/.*" \
  -x "$PLUGIN_NAME/docs/*" \
  -x "$PLUGIN_NAME/package.sh" \
  -x "$PLUGIN_NAME/*.md" \
  -x "$PLUGIN_NAME/openspec/*" \
  -x "$PLUGIN_NAME/*.zip" \
  > /dev/null

echo "✓ Created: $OUTPUT_FILE"
echo "  Size: $(du -h "$OUTPUT_FILE" | cut -f1)"
