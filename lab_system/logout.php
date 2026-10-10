<?php

include 'config/db.php';

session_start();


date_default_timezone_set('Asia/Manila');



// CHECK IF USER IS LOGGED IN

if(isset($_SESSION['user'])){


    // ===============================
    // CREATE SYSTEM LOG BEFORE LOGOUT
    // ===============================


    $user_id = $_SESSION['user']['user_id'];


    $username = $_SESSION['user']['first_name'];



    $activity = "Logged out of the system";


    $ip_address = $_SERVER['REMOTE_ADDR'];



    $log_date = date("Y-m-d");


    $log_time = date("H:i:s");





    $log_query = "

    INSERT INTO system_logs

    (

    user_id,

    username,

    activity,

    ip_address,

    log_date,

    log_time

    )


    VALUES

    (

    '$user_id',

    '$username',

    '$activity',

    '$ip_address',

    '$log_date',

    '$log_time'

    )

    ";



    $conn->query($log_query);



}



// ===============================
// DESTROY SESSION
// ===============================


// Remove all session data

session_unset();


// Destroy session

session_destroy();




// Redirect to login

header("Location: index.php");


exit();


?>