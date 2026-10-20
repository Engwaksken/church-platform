<?php
require_once '../config/config.php'; require_once '../config/db.php'; requireLogin();
$did=(int)($_GET['dept_id']??0); $mid=(int)($_GET['member_id']??0);
$conn->query("DELETE FROM department_members WHERE department_id=$did AND member_id=$mid");
setFlash('success','Member removed from department.');
redirect(BASE_URL."departments/view.php?id=$did");
