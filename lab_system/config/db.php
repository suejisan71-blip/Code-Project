<?php

$host = "127.0.0.1";
$username = "root";
$password = "";
$database = "lab_system";
$port = 3307;


// Create connection
$conn = new mysqli(
    $host,
    $username,
    $password,
    $database,
    $port
);


// Check connection
if($conn->connect_error){

    die("Database Connection Failed: " . $conn->connect_error);

}

?>