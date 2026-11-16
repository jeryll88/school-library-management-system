<?php
require_once __DIR__ . '/../server/config/auth.php';
clearSessionUser();
header('Location: login.php?logged_out=1');
exit;
