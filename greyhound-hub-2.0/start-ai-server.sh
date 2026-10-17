#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PYTHON=${GREYHOUND_AI_PYTHON:-"$SCRIPT_DIR/.venv_ai_mac/bin/python"}
AI_HOST=${GREYHOUND_AI_HOST:-127.0.0.1}
AI_PORT=${GREYHOUND_AI_PORT:-5001}
HEALTH_HOST=$AI_HOST

if [ "$HEALTH_HOST" = "0.0.0.0" ] || [ "$HEALTH_HOST" = "::" ]; then
    HEALTH_HOST=127.0.0.1
fi

if [ ! -x "$PYTHON" ]; then
    echo "macOS AI environment is missing. Create it with:"
    echo "  /opt/homebrew/bin/python3.11 -m venv \"$SCRIPT_DIR/.venv_ai_mac\""
    echo "  \"$SCRIPT_DIR/.venv_ai_mac/bin/python\" -m pip install -r \"$SCRIPT_DIR/requirements-inference.txt\""
    exit 1
fi

HEALTH_URL="http://$HEALTH_HOST:$AI_PORT/health"
if command -v curl >/dev/null 2>&1 && HEALTH_JSON=$(curl --fail --silent --max-time 2 "$HEALTH_URL"); then
    if printf '%s' "$HEALTH_JSON" | "$PYTHON" -c 'import json,sys; sys.exit(0 if json.load(sys.stdin).get("model_version") == "greyhound-hub-2.0-convnext" else 1)'; then
        echo "Greyhound ConvNeXt AI service is already running."
        echo "Health: $HEALTH_URL"
        exit 0
    fi
    echo "A different AI model is running on port $AI_PORT. Stop that AI process, then run this script again." >&2
    exit 1
fi

if command -v lsof >/dev/null 2>&1 && lsof -nP -iTCP:"$AI_PORT" -sTCP:LISTEN >/dev/null 2>&1; then
    echo "Cannot start the AI service: port $AI_PORT is already used by another process." >&2
    echo "Inspect it with: lsof -nP -iTCP:$AI_PORT -sTCP:LISTEN" >&2
    echo "Or choose another port: GREYHOUND_AI_PORT=5002 ./start-ai-server.sh" >&2
    exit 1
fi

cd "$SCRIPT_DIR"
echo "Starting Greyhound ConvNeXt AI service..."
exec "$PYTHON" api_server.py
