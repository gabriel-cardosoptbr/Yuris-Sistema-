<?php
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['error' => 'Este endpoint foi descontinuado. Use /api/resource_shares.php com codigo_vinculo.']);
