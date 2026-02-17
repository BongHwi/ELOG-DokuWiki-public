#!/bin/bash
# Plugin auto-installation script
# Reads version info from plugins.conf for reproducible builds

PLUGIN_DIR="/var/www/html/lib/plugins"
CONF_FILE="/tmp/plugins.conf"
TEMP_DIR=$(mktemp -d)
FAILED_PLUGINS=()

# plugins.conf is required — fail build if missing
if [ ! -f "$CONF_FILE" ]; then
    echo "ERROR: plugins.conf not found at $CONF_FILE"
    echo "       This file is required for reproducible builds."
    echo "       Ensure 'COPY plugins.conf /tmp/plugins.conf' is in Dockerfile."
    exit 1
fi

# Plugin installation function
install_plugin() {
    local name=$1
    local repo=$2
    local version=$3
    local url=""

    # Determine version format: commit SHA (7-40 hex chars) or tag
    if [[ "$version" =~ ^[0-9a-f]{7,40}$ ]]; then
        url="https://github.com/${repo}/archive/${version}.tar.gz"
    else
        url="https://github.com/${repo}/archive/refs/tags/${version}.tar.gz"
    fi

    echo "Installing plugin: $name (${repo}@${version})"

    cd "$TEMP_DIR" || return 1
    rm -rf ./*

    # Only allow pinned tag or commit SHA archives (no branches)
    if ! wget -qO plugin.tar.gz "$url" 2>/dev/null; then
        echo "  -> ERROR: Failed to download tag/SHA archive: $url"
        FAILED_PLUGINS+=("$name")
        return 1
    fi

    # Extract archive
    if ! tar xzf plugin.tar.gz 2>/dev/null; then
        echo "  -> ERROR: Failed to extract archive"
        FAILED_PLUGINS+=("$name")
        return 1
    fi
    rm plugin.tar.gz

    # Find extracted directory
    local extracted_dir=""
    for dir in */; do
        if [ -d "$dir" ]; then
            extracted_dir="${dir%/}"
            break
        fi
    done

    if [ -z "$extracted_dir" ]; then
        echo "  -> ERROR: No directory found after extraction"
        FAILED_PLUGINS+=("$name")
        return 1
    fi

    # Install
    rm -rf "${PLUGIN_DIR:?}/$name"
    if mv "$extracted_dir" "${PLUGIN_DIR}/$name"; then
        echo "  -> Installed to ${PLUGIN_DIR}/$name"
        return 0
    else
        echo "  -> ERROR: Failed to move to plugin directory"
        FAILED_PLUGINS+=("$name")
        return 1
    fi
}

# Parse plugins.conf and install each plugin
while IFS='=' read -r line || [ -n "$line" ]; do
    # Skip comments and empty lines
    [[ "$line" =~ ^#.*$ || -z "$line" ]] && continue

    name=$(echo "$line" | cut -d'=' -f1)
    repo=$(echo "$line" | cut -d'=' -f2)
    version=$(echo "$line" | cut -d'=' -f3)

    install_plugin "$name" "$repo" "$version"
done < "$CONF_FILE"

# Cleanup
rm -rf "$TEMP_DIR"

# Report results
echo ""
if [ ${#FAILED_PLUGINS[@]} -eq 0 ]; then
    echo "All plugins installed successfully"
    exit 0
else
    echo "WARNING: Failed to install ${#FAILED_PLUGINS[@]} plugin(s): ${FAILED_PLUGINS[*]}"
    exit 1
fi
