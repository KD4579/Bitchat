#!/bin/bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP_PID_FILE="$ROOT_DIR/.php-server.pid"
NODE_PID_FILE="$ROOT_DIR/.nodejs-server.pid"

mkdir -p "$ROOT_DIR/logs" "$ROOT_DIR/assets/logs"

is_running() {
    local pid_file="$1"
    local expected="$2"
    local port="$3"
    [ -f "$pid_file" ] || return 1
    local pid
    pid="$(cat "$pid_file")"
    [[ "$pid" =~ ^[0-9]+$ ]] || return 1
    kill -0 "$pid" 2>/dev/null || return 1
    ps -p "$pid" -o command= | grep -F -- "$expected" >/dev/null || return 1
    lsof -tiTCP:"$port" -sTCP:LISTEN 2>/dev/null | grep -Fx "$pid" >/dev/null
}

for command in php node npm mysql redis-cli curl lsof; do
    if ! command -v "$command" >/dev/null 2>&1; then
        echo "Missing required command: $command" >&2
        exit 1
    fi
done

if ! php -r 'exit(extension_loaded("mysqli") ? 0 : 1);'; then
    echo "PHP mysqli extension is required." >&2
    exit 1
fi

if ! mysql -u root bitchat -Nse 'SELECT 1 FROM Wo_Config LIMIT 1' >/dev/null 2>&1; then
    echo "Cannot connect to the local bitchat database. Start MySQL and verify config.php." >&2
    exit 1
fi

if ! redis-cli ping >/dev/null 2>&1; then
    echo "Redis is not running. Start it with: brew services start redis" >&2
    exit 1
fi

if [ ! -d "$ROOT_DIR/nodejs/node_modules" ]; then
    echo "Installing Node.js dependencies..."
    (cd "$ROOT_DIR/nodejs" && npm ci)
fi

if is_running "$PHP_PID_FILE" "-S 127.0.0.1:8000" 8000; then
    echo "PHP app already running: http://127.0.0.1:8000"
elif [ -n "$(lsof -tiTCP:8000 -sTCP:LISTEN 2>/dev/null || true)" ]; then
    echo "Port 8000 is occupied by a process not started from this project; stopping to avoid killing it." >&2
    exit 1
else
    nohup php \
        -d session.save_handler=files \
        -d session.gc_maxlifetime=14400 \
        -d session.cookie_httponly=1 \
        -d session.use_strict_mode=1 \
        -d session.cookie_secure=0 \
        -d session.cookie_samesite=Lax \
        -d memory_limit=256M \
        -d upload_max_filesize=1024M \
        -d post_max_size=1024M \
        -d max_execution_time=300 \
        -S 127.0.0.1:8000 -t "$ROOT_DIR" \
        > "$ROOT_DIR/php-server.log" 2>&1 < /dev/null &
    echo "$!" > "$PHP_PID_FILE"
fi

if is_running "$NODE_PID_FILE" "node main.js" 3000; then
    echo "Realtime service already running: http://127.0.0.1:3000"
elif [ -n "$(lsof -tiTCP:3000 -sTCP:LISTEN 2>/dev/null || true)" ]; then
    echo "Port 3000 is occupied by a process not started from this project; stopping to avoid killing it." >&2
    exit 1
else
    (
        cd "$ROOT_DIR/nodejs"
        nohup env BITCHAT_BIND_HOST=127.0.0.1 node main.js \
            > "$ROOT_DIR/nodejs-server.log" 2>&1 < /dev/null &
        echo "$!" > "$NODE_PID_FILE"
    )
fi

for attempt in $(seq 1 30); do
    if curl -fsS --max-time 2 http://127.0.0.1:8000/ >/dev/null \
        && curl -fsS --max-time 2 http://127.0.0.1:3000/ >/dev/null; then
        echo
        echo "Bitchat local environment is ready."
        echo "User panel:  http://127.0.0.1:8000/"
        echo "Admin panel: http://127.0.0.1:8000/admincp.php"
        echo "Database:    bitchat on 127.0.0.1:3306"
        echo "Realtime:    http://127.0.0.1:3000/"
        echo "Logs:        php-server.log, nodejs-server.log"
        echo "Stop with:   ./stop-localhost.sh"
        exit 0
    fi
    sleep 1
done

echo "Local services did not become ready. Check php-server.log and nodejs-server.log." >&2
exit 1
