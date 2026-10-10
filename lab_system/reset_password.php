<?php


include 'config/db.php';


date_default_timezone_set('Asia/Manila');


$token = $_GET['token'] ?? "";


$message = "";

$message_type = "";



// CHECK TOKEN

if(empty($token)){


    $message = "Invalid password reset request.";

    $message_type = "danger";


}




if(isset($_POST['change'])){


    $password = $_POST['password'];

    $confirm = $_POST['confirm'];



    if($password == $confirm){



        $check = $conn->query("

        SELECT * FROM users

        WHERE reset_token='$token'

        ");



        if($check->num_rows > 0){



            $conn->query("

            UPDATE users

            SET 

            password='$password',

            reset_token=NULL

            WHERE reset_token='$token'

            ");




            $message = "Password successfully changed! You may now login.";

            $message_type = "success";



        }

        else{


            $message = "Invalid or expired reset token.";

            $message_type = "danger";


        }



    }

    else{


        $message = "Password does not match!";

        $message_type = "danger";


    }



}



?>



<!DOCTYPE html>

<html lang="en">


<head>


<meta charset="UTF-8">


<meta name="viewport" content="width=device-width, initial-scale=1.0">


<title>Reset Password | NEUST Lab System</title>



<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">


<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">



<style>


*{

box-sizing:border-box;

}



body{


height:100vh;

margin:0;

display:flex;

justify-content:center;

align-items:center;

font-family:'Segoe UI',sans-serif;


background:

linear-gradient(

135deg,

#001f3f,

#0056b3,

#38bdf8

);


overflow:hidden;


}




body::before{


content:"";

position:absolute;

width:500px;

height:500px;

background:rgba(255,255,255,.12);

border-radius:50%;

top:-150px;

left:-150px;


}





body::after{


content:"";

position:absolute;

width:450px;

height:450px;

background:rgba(255,255,255,.1);

border-radius:50%;

bottom:-150px;

right:-100px;


}







.reset-card{


position:relative;

z-index:2;


width:420px;


padding:40px;


background:rgba(255,255,255,.18);


backdrop-filter:blur(18px);


border-radius:25px;


box-shadow:

0 25px 60px rgba(0,0,0,.3);



color:white;


}




.logo{


width:120px;

height:120px;

object-fit:contain;


display:block;

margin:auto;


background:white;


border-radius:50%;


padding:10px;


box-shadow:0 10px 25px rgba(0,0,0,.2);


}





.title{


text-align:center;

font-size:28px;

font-weight:700;

margin-top:20px;


}




.subtitle{


text-align:center;

font-size:14px;

opacity:.85;

margin-bottom:30px;


}





.form-control{


height:52px;


border-radius:15px;


border:none;


padding-left:20px;


}




.form-control:focus{


box-shadow:

0 0 0 3px rgba(255,255,255,.3);


}





.btn-reset{


width:100%;


height:52px;


border:none;


border-radius:15px;


background:white;


color:#0056b3;


font-weight:700;


font-size:16px;


transition:.3s;


}





.btn-reset:hover{


transform:translateY(-3px);


background:#e0f2fe;


}





.back-login{


display:block;

text-align:center;


margin-top:20px;


color:white;


text-decoration:none;


}



.back-login:hover{


text-decoration:underline;


}



</style>



</head>




<body>




<div class="reset-card">



<img 

src="assets/neust_logo.png"

class="logo"

alt="NEUST Logo"

>




<div class="title">

NEUST Lab System

</div>



<div class="subtitle">

Create your new password

</div>





<?php if(!empty($message)){ ?>


<div class="alert alert-<?php echo $message_type; ?>">

<?php echo $message; ?>

</div>


<?php } ?>







<form method="POST">





<div class="mb-3">


<input


type="password"


name="password"


class="form-control"


placeholder="New Password"


required>


</div>







<div class="mb-3">


<input


type="password"


name="confirm"


class="form-control"


placeholder="Confirm New Password"


required>


</div>







<button

type="submit"

name="change"

class="btn-reset">


<i class="bi bi-shield-lock"></i>

Change Password


</button>






</form>






<a href="index.php" class="back-login">


<i class="bi bi-arrow-left"></i>

Back to Login


</a>





</div>





</body>


</html>