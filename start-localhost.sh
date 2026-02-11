#!/bin/bash

# Bitchat Localhost Startup Script
# This script starts both the PHP server and Node.js real-time server

echo "🚀 Starting Bitchat Localhost Environment..."
echo ""

# Check if Redis is running
if ! redis-cli ping > /dev/null 2>&1; then
    echo "❌ Redis is not running. Please start Redis first:"
    echo "   brew services start redis"
    exit 1
fi

echo "✅ Redis is running"

# Check if MySQL is running
if ! mysql -u root -e "SELECT 1" > /dev/null 2>&1; then
    echo "❌ MySQL is not running. Please start MySQL first:"
    echo "   brew services start mysql"
    exit 1
fi

echo "✅ MySQL is running"
echo ""

# Kill any existing processes on ports 8000, 3000, and 3003
echo "🧹 Cleaning up existing processes..."
lsof -ti:8000 | xargs kill -9 2>/dev/null
lsof -ti:3000 | xargs kill -9 2>/dev/null
lsof -ti:3003 | xargs kill -9 2>/dev/null

echo ""
echo "📦 Checking Node.js dependencies..."
cd nodejs
if [ ! -d "node_modules" ] || [ ! -f "node_modules/.package-lock.json" ]; then
    echo "📥 Installing Node.js dependencies..."
    npm install > /dev/null 2>&1
    echo "✅ Dependencies installed"
else
    echo "✅ Dependencies already installed"
fi
cd ..

echo ""
echo "📡 Starting servers..."
echo ""

# Start PHP server in background
echo "🐘 Starting PHP server on http://localhost:8000"
php -S localhost:8000 > php-server.log 2>&1 &
PHP_PID=$!

# Wait a moment for PHP to start
sleep 2

# Start Node.js server in background
echo "⚡ Starting Node.js Socket.io server on port 3000..."
cd nodejs && node main.js > ../nodejs-server.log 2>&1 &
NODE_PID=$!
cd ..

# Wait for servers to initialize
sleep 3

echo ""
echo "✅ Bitchat is now running!"
echo ""
echo "📍 URLs:"
echo "   • Main App: http://localhost:8000"
echo "   • Admin Panel: http://localhost:8000/admin-panel"
echo ""
echo "🔍 Process IDs:"
echo "   • PHP Server: $PHP_PID"
echo "   • Node.js Server: $NODE_PID"
echo ""
echo "📋 Logs:"
echo "   • PHP logs: tail -f php-server.log"
echo "   • Node.js logs: tail -f nodejs-server.log"
echo ""
echo "⛔ To stop servers, run: ./stop-localhost.sh"
echo "   Or press Ctrl+C to stop this script (servers will keep running)"
echo ""

# Save PIDs for stop script
echo "$PHP_PID" > .php-server.pid
echo "$NODE_PID" > .nodejs-server.pid

# Keep script running
wait
