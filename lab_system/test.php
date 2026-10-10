<?php

include 'config/db.php';


echo "<h2>Database Connection Test</h2>";


if($conn){

    echo "<p style='color:green;'>
     Database Connected Successfully!
    </p>";

    echo "<p>
    Database Name: lab_system
    </p>";

    echo "<p>
    Server: localhost
    </p>";

}

else{

    echo "<p style='color:red;'>
     Database Connection Failed!
    </p>";

}

?>