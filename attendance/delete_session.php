<?php
require_once '../config/config.php';
requireLogin();
$date = san($_GET['date']??'');
$type = san($_GET['type']??'');
mysqli_query($conn,"DELETE FROM attendance WHERE service_date='$date' AND service_type='$type'");
setFlash('Attendance session deleted.','success');
redirect('index.php');
