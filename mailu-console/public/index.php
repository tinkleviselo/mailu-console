<?php
/**
 * Mailu Console - front controller.
 */

$router = require dirname(__DIR__) . '/app/bootstrap.php';
$router->dispatch();