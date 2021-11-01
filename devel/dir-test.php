<?php

define('IN_SCRIPT', 1);

$root_path = './../';

require_once( $root_path. 'common.php' );

$basePath = realpath($root_path . 'www/v/');

/** @var SplFileInfo[] $files */
$files = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($basePath),
	RecursiveIteratorIterator::LEAVES_ONLY
);

foreach( $files as $filename => $file ) {

	if( $file->isDir() ) {

		echo "dir ";

	}

	// Get real and relative path for current file
	$filePath = $file->getRealPath();
	$relativePath = substr($filePath, strlen($basePath) + 1);

	echo "$filename vs $relativePath vs " . $file->getPath() . " vs " . $file->getBasename() . " vs " . $file->getFilename() . "\n";

}