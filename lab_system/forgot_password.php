<?php


include 'config/db.php';


date_default_timezone_set('Asia/Manila');


$message = "";

$message_type = "";



if(isset($_POST['submit'])){


    $email = $_POST['email'];



    $query = $conn->query(

        "SELECT * FROM users WHERE email='$email'"

    );



    if($query->num_rows > 0){



        $token = md5(rand());



        $conn->query("

        UPDATE users

        SET reset_token='$token'

        WHERE email='$email'

        ");




        header(

        "Location: reset_password.php?token=$token"

        );


        exit();



    }

    else{



        $message = "Email address not found!";

        $message_type = "danger";


    }



}



?>



<!DOCTYPE html>

<html lang="en">


<head>


<meta charset="UTF-8">


<meta name="viewport" content="width=device-width, initial-scale=1.0">


<title>Forgot Password | NEUST Lab System</title>



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


right:-100px;



animation:float 10s infinite alternate;


}





@keyframes float{


from{

transform:translateY(0);

}


to{

transform:translateY(40px);

}


}





.reset-card{


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


font-size:30px;


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


}





.form-control:focus{


box-shadow:

0 0 0 4px rgba(255,255,255,.25);


}





.btn-reset{


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





.btn-reset:hover{


transform:translateY(-3px);


background:#e0f2fe;


}





.back-login{


display:block;



margin-top:20px;



color:white;



text-decoration:none;



font-size:14px;


}





.back-login:hover{


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

Password Recovery

</div>






<?php if(!empty($message)){ ?>


<div class="alert alert-<?php echo $message_type; ?>">


<?php echo $message; ?>


</div>


<?php } ?>







<form method="POST">





<input


type="email"


name="email"


class="form-control mb-3"


placeholder="Enter registered email"


required>






<button


type="submit"


name="submit"


class="btn-reset">


<i class="bi bi-envelope-check"></i>


Continue


</button>





</form>






<a href="index.php" class="back-login">


<i class="bi bi-arrow-left"></i>


Back to Login


</a>






<div class="footer">


Nueva Ecija University of Science and Technology


<br>


Laboratory Management System


</div>






</div>





</body>


</html>