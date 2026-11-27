#!/bin/bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

stop_service() {
    local name="$1"
    local pid_file="$2"
    local expected="$3"
    local port="$4"

    if [ ! -f "$pid_file" ]; then
        echo "$name: no managed PID file."
        return
    fi

    local pid
    pid="$(cat "$pid_file")"
    if [[ ! "$pid" =~ ^[0-9]+$ ]]; then
        echo "$name: invalid PID file; left untouched." >&2
        return
    fi

    if ! kill -0 "$pid" 2>/dev/null; then
        rm "$pid_file"
        echo "$name: removed stale PID file."
        return
    fi

    local command
    command="$(ps -p "$pid" -o command= || true)"
    local port_pid
    port_pid="$(lsof -tiTCP:"$port" -sTCP:LISTEN 2>/dev/null || true)"
    if [[ "$command" != *"$expected"* || ! " $port_pid " == *" $pid "* ]]; then
        echo "$name: PID or port does not match this project; left untouched." >&2
        return
    fi

    kill "$pid"
    for _ in $(seq 1 10); do
        kill -0 "$pid" 2>/dev/null || break
        sleep 1
    done
    if kill -0 "$pid" 2>/dev/null; then
        echo "$name: did not stop; check the process manually (PID $pid)." >&2
        return
    fi

    rm "$pid_file"
    echo "$name: stopped."
}

stop_service "PHP app" "$ROOT_DIR/.php-server.pid" "-S 127.0.0.1:8000" 8000
stop_service "Realtime service" "$ROOT_DIR/.nodejs-server.pid" "node main.js" 3000
