<?php
// Autoloader.php

spl_autoload_register(function ($class) {
    // 1. Защита от Path Traversal и нормализация слэшей для пространств имен (PSR-4)
    $cleanClass = str_replace(['..', '\\'], ['', '/'], $class);

    $dirs = [
        __DIR__ . '/',                  // api/core/
        __DIR__ . '/ai/',               // api/core/ai/
        __DIR__ . '/services/',    
        dirname(__DIR__) . '/modules/',     // api/core/services/
        __DIR__ . '/WebSearch/',               
    ];

    foreach ($dirs as $dir) {
        $file = $dir . $cleanClass . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});