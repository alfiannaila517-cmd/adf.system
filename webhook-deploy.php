<?php

header('Content-Type: application/json');
header('Cache-Control: no-cache');

echo json_encode([
    'status' => 'disabled',
    'message' => 'Auto deploy disabled. Deploy only through git version control.',
    'time' => date('Y-m-d H:i:s'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
