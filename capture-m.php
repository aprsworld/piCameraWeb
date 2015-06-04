#!/usr/bin/php -q
<?
$m = new Memcached();
$m->addServer('localhost', 11211);

$key_prefix='CONFIG_CAMERA_';
$config['DIR_LATEST']=$m->get($key_prefix . 'DIR_LATEST');
$config['IMAGE_SCALED_WIDTH']=$m->get($key_prefix . 'IMAGE_SCALED_WIDTH');
$config['IMAGE_SCALED_HEIGHT']=$m->get($key_prefix . 'IMAGE_SCALED_HEIGHT');
$config['IMAGE_ROTATION']=$m->get($key_prefix . 'IMAGE_ROTATION');
$config['EPEG_ARGS']=$m->get($key_prefix . 'EPEG_ARGS');
$config['RASPISTILL_ARGS']=$m->get($key_prefix . 'RASPISTILL_ARGS');
$config['RASPISTILL_WIDTH']=$m->get($key_prefix . 'RASPISTILL_WIDTH');
$config['RASPISTILL_HEIGHT']=$m->get($key_prefix . 'RASPISTILL_HEIGHT');
$config['DIR_ACTION']=$m->get($key_prefix . 'DIR_ACTION');

print_r($config);
$m->quit();
die("done");



/* locations */
define('DIR_LATEST','/run/shm/cam/latest');

/* image settings */
define('IMAGE_SCALED_WIDTH',1280);
define('IMAGE_SCALED_HEIGHT',720);
define('IMAGE_ROTATION',180);

/* resize settings */
define('EPEG_ARGS','-q 75');

/* capture settings*/
define('RASPISTILL_ARGS','-q 75 -n --exposure night --awb auto');
define('RASPISTILL_WIDTH',1920);
define('RASPISTILL_HEIGHT',1080);

/* actions */
define('DIR_ACTION','/var/www/cam/actions');

$runAtSecond=array();
if ( 2 == $_SERVER['argc'] ) {
	$runAtSecond=explode(',',$_SERVER['argv'][1]);

	// print_r($runAtSecond);

	for ( $i=0 ; $i<count($runAtSecond) ; $i++ ) {
		if ( ! is_numeric($runAtSecond[$i]) || $runAtSecond[$i] < 0 || $runAtSecond[$i] > 59 ) {
			$runAtSecond=array();
			break;
		}
	}
}


/* resize image */
function do_resize($timestamp,$full,$scaled) {
	$cmd_resize=sprintf("epeg -w %s -h %s %s %s %s",
		IMAGE_SCALED_WIDTH,
		IMAGE_SCALED_HEIGHT,
		EPEG_ARGS,
		$full,
		$scaled
	);
		

	printf("# resizing with command:\n'%s'\n",$cmd_resize);

	$microtime_start = microtime(true);
	passthru($cmd_resize);
	$microtime_stop = microtime(true);

	printf("# resize took %0.2f seconds\n\n",$microtime_stop - $microtime_start);

	return 0;
}

/* capture image */
function do_capture($timestamp,$full,$scaled) {
	/* attempt to create output directory */
	if ( ! is_dir(DIR_LATEST) ) {
		printf("# creating DIR_LATEST %s\n",DIR_LATEST);
		if ( ! mkdir(DIR_LATEST,0777,true) ) {
			printf("# error creating DIR_LATEST. Aborting.\n");
			return 1;
		}
	}

	/* capture image with raspistill */
	$cmd_capture=sprintf("sudo raspistill -w %s -h %s -o %s %s -rot %s",RASPISTILL_WIDTH,RASPISTILL_HEIGHT,$full,RASPISTILL_ARGS,IMAGE_ROTATION);

	printf("# capturing with command:\n'%s'\n",$cmd_capture);

	$microtime_start = microtime(true);
	passthru($cmd_capture);
	$microtime_stop = microtime(true);

	printf("# capture took %0.2f seconds\n\n",$microtime_stop - $microtime_start);

	return 0;
}

/* execute scripts in action_dir */
function do_actions($timestamp,$full,$scaled) {
	printf("# Scanning action directory for available scripts\n");
	if ( ! is_dir(DIR_ACTION) ) {
		printf("# DIR_ACTION %s is not a directory\n");
		return;
	}

	/* open directory and build list of executeable scripts */
	$scripts=array();
	$dir=opendir(DIR_ACTION);
	while ( ($file = readdir($dir) ) !== false ) {
		$filefullpath=DIR_ACTION . '/' . $file;

		/* skip enteries that start with '.' */
		if ( '.' == substr($file,0,1) )
			continue;

		/* skip files that aren't executable */
		if ( ! is_executable($filefullpath) )
			continue;

		/* add to scripts array */
		$scripts[]=$filefullpath;
	}
	closedir($dir);

	$args=$timestamp . ' ' . escapeshellarg($full) . ' ' . escapeshellarg($scaled);

	/* execute scripts */
	for ( $i=0 ; $i<count($scripts) ; $i++ ) {
		$microtime_start = microtime(true);
		printf("# ACTION[%d] executing %s %s\n",$i,$scripts[$i],$args);
		passthru($scripts[$i] . ' ' . $args);
		$microtime_stop = microtime(true);

		printf("# ACTION[%d] took %0.2f seconds\n\n",$i,$microtime_stop - $microtime_start);
	}
}

/* start of script time */
$timestamp=time();
$thisSecond=$timestamp%60;
printf("## capture.php @ %s runs at {%s}\r",date("Y-m-d H:i:s",$timestamp),implode(",",$runAtSecond));

/* only run at seconds that match our runAtSecond array */
if ( count($runAtSecond) && ! in_array($thisSecond,$runAtSecond) ) {
	return 0;
}

printf("\n");



/* setup filenames */
$full  =sprintf("%s/latest.jpg",DIR_LATEST);
$scaled=sprintf("%s/latest.%dx%d.jpg",DIR_LATEST,IMAGE_SCALED_WIDTH,IMAGE_SCALED_HEIGHT);

/* capture image */
$err = do_capture($timestamp,$full,$scaled);
if ( $err ) {
	die("# Error in do_capture()\n");
}

/* resize image */
if ( RASPISTILL_WIDTH == IMAGE_SCALED_WIDTH && RASPISTILL_HEIGHT == IMAGE_SCALED_HEIGHT ) {
	$cmd_ln=sprintf("ln -sf %s %s",$full,$scaled);
	exec($cmd_ln);
} else {
	$err = do_resize($timestamp,$full,$scaled);
	if ( $err ) {
		die("# Error in do_resize()\n");
	}
}


/* do user actions */
do_actions($timestamp, $full, $scaled);

?>
