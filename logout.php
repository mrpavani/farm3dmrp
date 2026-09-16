<?php
require_once __DIR__ . '/includes/auth.php';
fazerLogout();
header('Location: login.php');
exit;
