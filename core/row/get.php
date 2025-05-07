<?php

require_once '../../../core/rowManager.php';
require_once '../../../core/response.php';

header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] === "GET") {


    $validated_data = [];

    $validated_data['table'] = isset($_GET['table']) ? htmlspecialchars($_GET['table'], ENT_QUOTES, 'UTF-8') : null;
    $validated_data['page'] = isset($_GET['page']) ? filter_var($_GET['page'], FILTER_SANITIZE_NUMBER_INT) : null;
    $validated_data['limit'] = isset($_GET['limit']) ? filter_var($_GET['limit'], FILTER_SANITIZE_NUMBER_INT) : null;
    $validated_data['column'] =  isset($_GET['column']) ? htmlspecialchars($_GET['column'], ENT_QUOTES, 'UTF-8') : null;
    $validated_data['database'] = isset($_GET['database']) ? htmlspecialchars($_GET['database'], ENT_QUOTES, 'UTF-8') : null;

    // Handle the 'condition' parameter separately to decode the JSON
    if (isset($_GET['condition'])) {
        $condition_string = $_GET['condition'];
        $decoded_condition = json_decode($condition_string, true);

        if ($decoded_condition !== null && json_last_error() === JSON_ERROR_NONE) {
            $validated_data['condition'] = $decoded_condition;
        } else {
            $validated_data['condition_raw'] = htmlspecialchars($condition_string, ENT_QUOTES, 'UTF-8');
            // Optionally log an error here about invalid JSON in 'condition'
        }
    } else {
        $validated_data['condition'] = null;
    }

    // $input = json_encode($validated_data);
    getTableRow($validated_data);
} else {
    jsonResponse(false, "GET request required.");
}
