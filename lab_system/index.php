<?php

error_reporting(E_ALL);
ini_set('display_errors',1);


session_start();


include 'config/db.php';


date_default_timezone_set('Asia/Manila');




if(isset($_POST['login'])){


$email=$_POST['email'];

$password=$_POST['password'];




$stmt=$conn->prepare(

"SELECT * FROM users WHERE email=? AND password=?"

);



$stmt->bind_param(

"ss",

$email,

$password

);



$stmt->execute();



$result=$stmt->get_result();





if($result->num_rows>0){



$user=$result->fetch_assoc();



$_SESSION['user']=$user;





// =====================================
// SYSTEM LOG - LOGIN SUCCESS
// =====================================



$user_id=$user['user_id'];


$username=$user['first_name'];



$activity="Logged into the system";



$ip=$_SERVER['REMOTE_ADDR'];



$date=date("Y-m-d");


$time=date("H:i:s");





$conn->query("

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

'$ip',

'$date',

'$time'

)


");






header("Location: dashboard.php");


exit();




}

else{



$error="Invalid email or password!";



}




}



?>



<!DOCTYPE html>

<html lang="en">


<head>


<meta charset="UTF-8">


<meta name="viewport" content="width=device-width, initial-scale=1.0">


<title>NEUST Lab System Login</title>





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


width:550px;


height:550px;


background:rgba(255,255,255,.12);


border-radius:50%;


top:-180px;


left:-180px;


animation:float 8s infinite alternate;


}





body::after{


content:"";


position:absolute;


width:450px;


height:450px;


background:rgba(255,255,255,.1);


border-radius:50%;


bottom:-150px;


right:-120px;


animation:float 10s infinite alternate;


}





@keyframes float{


from{

transform:translateY(0px);

}


to{

transform:translateY(40px);

}


}






.login-card{


position:relative;


z-index:2;



width:430px;


padding:40px;



background:


rgba(255,255,255,.18);



backdrop-filter:blur(18px);



border-radius:30px;



box-shadow:


0 30px 70px rgba(0,0,0,.35);



color:white;



text-align:center;


}






.logo{


width:120px;


height:120px;


object-fit:contain;


background:white;


border-radius:50%;


padding:10px;


margin-bottom:15px;



box-shadow:


0 10px 30px rgba(0,0,0,.25);


}







.title{


font-size:32px;


font-weight:800;


}




.subtitle{


font-size:15px;


opacity:.85;


margin-bottom:30px;


}






.form-control{


height:52px;


border-radius:15px;


border:none;


padding-left:20px;


font-size:15px;


}





.form-control:focus{


box-shadow:


0 0 0 4px rgba(255,255,255,.25);


}






.btn-login{


width:100%;


height:52px;


border-radius:15px;


border:none;



background:white;


color:#0056b3;


font-weight:800;


font-size:16px;



transition:.3s;


}





.btn-login:hover{


transform:translateY(-3px);


background:#e0f2fe;


}






.forgot{


display:block;


margin-top:20px;


color:white;


text-decoration:none;


font-size:14px;


}





.forgot:hover{


text-decoration:underline;


}





.footer{


margin-top:25px;


font-size:12px;


opacity:.8;


}



</style>



</head>



<body>




<div class="login-card">






<img

src="assets/neust_logo.png"

class="logo"

alt="NEUST Logo"

>






<div class="title">

NEUST Lab System

</div>






<div class="subtitle">

Student Attendance & Monitoring System

</div>








<?php

if(isset($error)){


echo '

<div class="alert alert-danger">

'.$error.'

</div>';



}



?>








<form method="POST">






<input


type="email"


name="email"


class="form-control mb-3"


placeholder="Email Address"


required>








<input


type="password"


name="password"


class="form-control mb-3"


placeholder="Password"


required>








<button


type="submit"


name="login"


class="btn-login">



<i class="bi bi-box-arrow-in-right"></i>


Login



</button>







</form>







<a href="forgot_password.php" class="forgot">


<i class="bi bi-key"></i>


Forgot Password?



</a>








<div class="footer">


Nueva Ecija University of Science and Technology


<br>

Laboratory Management System


</div>







</div>





</body>


</html>