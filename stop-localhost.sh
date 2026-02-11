#!/bin/bash

# Bitchat Localhost Stop Script
# This script stops both the PHP server and Node.js real-time server

echo "⛔ Stopping Bitchat Localhost Environment..."
echo ""

# Kill processes by PID if files exist
if [ -f .php-server.pid ]; then
    PHP_PID=$(cat .php-server.pid)
    if kill -0 $PHP_PID 2>/dev/null; then
        kill $PHP_PID
        echo "✅ Stopped PHP server (PID: $PHP_PID)"
    fi
    rm .php-server.pid
fi

if [ -f .nodejs-server.pid ]; then
    NODE_PID=$(cat .nodejs-server.pid)
    if kill -0 $NODE_PID 2>/dev/null; then
        kill $NODE_PID
        echo "✅ Stopped Node.js server (PID: $NODE_PID)"
    fi
    rm .nodejs-server.pid
fi

# Also kill any processes on these ports
lsof -ti:8000 | xargs kill -9 2>/dev/null && echo "✅ Killed any remaining processes on port 8000"
lsof -ti:3000 | xargs kill -9 2>/dev/null && echo "✅ Killed any remaining processes on port 3000"
lsof -ti:3003 | xargs kill -9 2>/dev/null && echo "✅ Killed any remaining processes on port 3003"

echo ""
echo "🛑 All servers stopped"
