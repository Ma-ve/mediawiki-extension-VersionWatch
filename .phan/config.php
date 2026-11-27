<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

// Echo is optional: its classes are only used when it is installed.
$IP = getenv( 'MW_INSTALL_PATH' ) !== false ? getenv( 'MW_INSTALL_PATH' ) : '../..';
$cfg['directory_list'][] = "$IP/extensions/Echo";
$cfg['exclude_analysis_directory_list'][] = "$IP/extensions/Echo";

return $cfg;
