<?php
session_start();

session_unset();
session_destroy();

header('Location: /sps/customer_login.php');
exit;
