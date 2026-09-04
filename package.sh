#!/bin/bash
# Package the RSVP Migration Load Generator plugin into a distributable ZIP file

set -e

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_NAME="rsvp-migration-loadgen"
VERSION=$(grep "define( 'TEC_DATA_GENERATOR_VERSION'" "$PLUGIN_DIR/$PLUGIN_NAME.php" | grep -o "'[^']*'" | tail -1 | tr -d "'")
OUTPUT_FILE="$PLUGIN_DIR/${PLUGIN_NAME}-${VERSION}.zip"

echo "Packaging $PLUGIN_NAME v$VERSION..."
cd "$(dirname "$PLUGIN_DIR")"

# Create zip, explicitly excluding dev files and directories
zip -r "$OUTPUT_FILE" "$PLUGIN_NAME" \
  -x "$PLUGIN_NAME/.git/*" \
  -x "$PLUGIN_NAME/.git" \
  -x "$PLUGIN_NAME/.claude/*" \
  -x "$PLUGIN_NAME/.codegraph/*" \
  -x "$PLUGIN_NAME/.code-review-graph/*" \
  -x "$PLUGIN_NAME/.superpowers/*" \
  -x "$PLUGIN_NAME/docs/*" \
  -x "$PLUGIN_NAME/.gitignore" \
  -x "$PLUGIN_NAME/package.sh" \
  -x "$PLUGIN_NAME/*.md" \
  > /dev/null

echo "✓ Created: $OUTPUT_FILE"
echo "  Size: $(du -h "$OUTPUT_FILE" | cut -f1)"
