<?php
declare(strict_types=1);

use RoamingNepal\PnrConverter\Support\Auth;

require_once __DIR__ . '/app/bootstrap.php';

Auth::logout();
header('Location: login.php');
exit;
