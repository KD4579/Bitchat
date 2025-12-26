<?php
if (!isset($woRedis) || !$woRedis['enabled']) {
    return;
}

static $redis;

if (!$redis) {
    $redis = new Redis();
    $redis->connect($woRedis['host'], $woRedis['port'], 2);
    $redis->select($woRedis['db']);
}

function wo_cache_get($key) {
    global $redis;
    return $redis->get('wo:' . $key);
}

function wo_cache_set($key, $value, $ttl = 300) {
    global $redis;
    return $redis->setex('wo:' . $key, $ttl, serialize($value));
}

function wo_cache_del($key) {
    global $redis;
    return $redis->del('wo:' . $key);
}

