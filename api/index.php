<?php

// Forward request dari Vercel ke index.php milik Laravel di folder public
$_SERVER['SCRIPT_NAME'] = '/index.php';

require __DIR__ . '/../public/index.php';