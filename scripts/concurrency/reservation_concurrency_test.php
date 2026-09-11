<?php
$argv = [__FILE__, 'reservation', $argv[1] ?? 'default'];
require __DIR__.'/run_concurrency.php';
