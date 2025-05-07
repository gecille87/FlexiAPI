<?php

require_once '../../../core/columnManager.php';
require_once '../../../core/response.php';

header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $input = json_decode(file_get_contents("php://input"), true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400); // Bad Request
        jsonResponse(false, "Invalid JSON input.");
    }

    // Sanitize and validate the dynamic input
    $sanitized_data = sanitizeDynamicInput($input);

    // Check if sanitization/validation failed
    if ($sanitized_data === false) {
        http_response_code(400); // Bad Request
        jsonResponse(false, "Invalid or unsafe input data.");
    }

    updateTableColumn($sanitized_data); // Pass the sanitized data

} else {
    http_response_code(405); // Method Not Allowed
    jsonResponse(false, "POST request required.");
}