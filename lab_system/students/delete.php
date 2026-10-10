<?php

include '../config/db.php';

session_start();


date_default_timezone_set('Asia/Manila');


// CHECK LOGIN

if(!isset($_SESSION['user'])){

    header("Location: ../index.php");

    exit();

}




if(isset($_GET['id'])){


    $id = intval($_GET['id']);



    // ===============================
    // GET STUDENT INFORMATION FIRST
    // ===============================


    $student_query = "

    SELECT *

    FROM students

    WHERE stud_id='$id'

    ";


    $student_result = $conn->query($student_query);



    if($student_result->num_rows > 0){


        $student = $student_result->fetch_assoc();



        $student_name =

        $student['first_name']." ".

        $student['last_Name'];



        // ===============================
        // DELETE STUDENT
        // ===============================


        $delete_query = "

        DELETE FROM students

        WHERE stud_id='$id'

        ";



        if($conn->query($delete_query)){



            // RESET AUTO INCREMENT

            $conn->query(

            "ALTER TABLE students AUTO_INCREMENT = 1"

            );





            // ===============================
            // INSERT SYSTEM LOG
            // ===============================



            $user_id = $_SESSION['user']['user_id'];


            $username = $_SESSION['user']['first_name'];



            $activity =

            "Deleted student: ".$student_name;



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





            header("Location: list.php");

            exit();



        }


        else{


            echo "

            Error deleting student:

            ".$conn->error;


        }



    }

    else{


        echo "

        Student not found

        ";


    }



}

else{


    header("Location:list.php");

    exit();


}


?>