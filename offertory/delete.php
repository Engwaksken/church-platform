<?php
require_once '../config/config.php'; requireLogin();
$id = intval($_GET['id']??0);
mysqli_query($conn,"DELETE FROM offertory WHERE id=$id");
setFlash('Offertory record deleted.','success');
redirect('index.php');
