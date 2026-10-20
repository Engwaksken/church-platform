<?php
require_once '../config/config.php';
requireLogin();
$id = intval($_GET['id']??0);
mysqli_query($conn,"DELETE FROM contributions WHERE id=$id");
setFlash('Contribution deleted.','success');
redirect('index.php');
