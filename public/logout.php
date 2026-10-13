<?php
/**
 * QRaft — sign out.
 */

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

logout_user();
flash('You have been signed out.', 'info');
redirect('index.php');
