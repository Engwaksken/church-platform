<?php
require_once '../config/config.php'; requireLogin();
$id = intval($_GET['id']??0);
mysqli_query($conn,"DELETE FROM thanksgiving WHERE id=$id");
setFlash('Thanksgiving record deleted.','success');
redirect('index.php');
