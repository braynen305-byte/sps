<?php
session_start();

session_unset();
session_destroy();

header('Location: /sps/login.php');
exit;
