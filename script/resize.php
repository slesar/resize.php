<?php


// stop script execution
// in case of any error - return 404
function do_exit() {
	header("HTTP/1.0 404 Not Found");
	exit;
}

// create image from file
function open_image($src) {
	// get image info
	$info = getImageSize($src);

	$image = false;

	// open source image
	switch ($info[2]) {
	case IMAGETYPE_PNG:
		@$image = imageCreateFromPNG($src);
		break;

	case IMAGETYPE_GIF:
		@$image = imageCreateFromGIF($src);
		break;

	case IMAGETYPE_WBMP:
		@$image = imageCreateFromWBMP($src);
		break;

	case IMAGETYPE_JPEG:
	default:
		@$image = imageCreateFromJPEG($src);
	}

	// combine image and dimensions into one array if image was created
	return $image ? array($image, $info[0], $info[1]) : false;
}


// regular expression to find path parameters
$matcher =
	"/^"
		// path to image considering 'resize/'
		."(.*?\/)?(img\/.*\/)(resize\/)(.+)"

		// delimeter
		."(_)"

		// parameters
		//  p - constrain proportions
		//  g - grayscale
		."([pg]*)"

		// width x height
		."(\d{1,4}){0,1}(x)(\d{1,4}){0,1}"

		// native extension
		."\.(jpe{0,1}g|gif|png|bmp|tiff{0,1})"

		// new extension
		."\.?(jpg|gif|png)?"

	."$/";

// search for parameters in request string
if (!preg_match($matcher, $_SERVER['REQUEST_URI'], $p)) {
	do_exit();
}

// construct new array with configuration
$p = array(
	'path'    => $p[2],
	'resize'  => $p[3],
	'name'    => $p[4],
	'params'  => $p[6],
	'width'   => $p[7],
	'height'  => $p[9],
	'ext'     => $p[10],
	'newext'  => isset($p[11]) ? $p[11] : false
);

// original file name
$name = "../". $p['path'] . $p['name'] .".". $p['ext'];

// if no original file
if (!file_exists($name)) {
	do_exit();
}

// target path
$folder = "../". $p['path'] . $p['resize'];

// if no target path exists
if (!is_dir($folder)) {
	do_exit();
}

// new file name without extension
$newname = $folder . $p['name'] ."_". $p['params'] . $p['width'] ."x". $p['height'];

// target width and height
$tw = intval($p['width']);
$th = intval($p['height']);

// if none passed
if ($tw < 16 && $th < 16) {
	do_exit();
}

// read local configuration
$conf = "../". $p['path'] .".resize_conf";
if (file_exists($conf)) {
	$conf = parse_ini_file($conf, 1);
	
	// check for target image type
	if ($p['newext'] && isset($conf['output']['extensions'])) {
		$ext = preg_split('/\s*,\s*/', $conf['output']['extensions']);
		
		// if type is not allowed
		if (!in_array($p['newext'], $ext)) {
			$p['newext'] = false;
		}
	} else {
		$p['newext'] = false;
	}

	// check for configuration
	if (isset($conf['output']['sizes'])) {
		$sizes = preg_split('/\s*,\s*/', $conf['output']['sizes']);

		// if configuration is not allowed
		if (!in_array($p['params'] . $p['width'] .'x'. $p['height'], $sizes)) {
			do_exit();
		}
	}
} else {
	$conf = false;
}


// open source image
$info = open_image($name);

// if no image created
if (!$info) {
	do_exit();
}

$src_img = $info[0];

// get image dimensions
$sw = $info[1];
$sh = $info[2];

// update target dimensions
$tw = min($tw, $sw);
$th = min($th, $sh);


// calculate sizes
if (!$tw) {
	$tw = intval($sw*$th/$sh);
}

if (!$th) {
	$th = intval($sh*$tw/$sw);
}

// if none valid
if ($tw < 16 && $th < 16) {
	do_exit();
}

// constrain proportions
if (strpos($p['params'], 'p') !== false) {
	if ($sw > $sh && $tw > $th) {

	} else {
		$a = $tw;
		$tw = $th;
		$th = $a;
	}
}

$w = $sw;
$h = $w * $th / $tw;

if ($h > $sh) {
	$h = $sh;
	$w = $h * $tw / $th;
}

// calculate cropped position in original photo
// center by X
$x = floor(($sw-$w)/2);

// user-defined by Y
if ($conf && isset($conf['crop']['position'])) {
	$pos = intval($conf['crop']['position']);
	$pos = min(100, max(0, $pos));

	$y = floor(($sh-$h) * $pos/100);
} else {
	$y = 0;
}

$w = floor($w);
$h = floor($h);


// create target image
$targ_img = imageCreateTrueColor($tw, $th);

// crop source image
imageCopy($src_img, $src_img, 0, 0, $x, $y, $w, $h);

// copy and resize image
imageCopyResampled($targ_img, $src_img, 0, 0, 0, 0, $tw, $th, $w, $h);

// watermark
if ($conf && isset($conf['watermark']['src']) && file_exists($conf['watermark']['src'])) {
	$wmark = open_image($conf['watermark']['src']);

	$x = 0;
	$y = 0;
	$margin = isset($conf['watermark']['margin']) ? $conf['watermark']['margin'] : 0;

	if (isset($conf['watermark']['position'])) {
		if ($conf['watermark']['position'] == 'tiled') {
			// tiled watermark
		} else {
			$pos = preg_split('/\s*,\s*/', $conf['watermark']['position']);

			if (in_array('left', $pos)) {
				$x = $margin;
			} else if (in_array('right', $pos)) {
				$x = $tw - $wmark[1] - $margin;
			} else {
				$x = floor(($tw - $wmark[1]) / 2);
			}
			
			if (in_array('top', $pos)) {
				$y = $margin;
			} else if (in_array('bottom', $pos)) {
				$y = $th - $wmark[2] - $margin;
			} else {
				$y = floor(($th - $wmark[2]) / 2);
			}
		}
	} else {
		$x = $y = $margin;
	}

	if ($conf['watermark']['position'] == 'tiled') {
		$tmp = imageCreateTrueColor($tw, $th);

		// copy all background
    	imageCopy($tmp, $targ_img, 0, 0, 0, 0, $tw, $th);

    	// tile watermark
    	for ($i = 0; $i < $tw; $i += $wmark[1]) {
    		for ($j = 0; $j < $th; $j += $wmark[2]) {
    			imagecopy($tmp, $wmark[0], $i, $j, 0, 0, $wmark[1], $wmark[2]);
    		}
    	}

    	imageDestroy($wmark[0]);

    	// replace watermark with tiled image
		$wmark[0] = $tmp;
		$wmark[1] = $tw;
		$wmark[2] = $th;
	}

	$opacity = isset($conf['watermark']['opacity']) ? $conf['watermark']['opacity'] : 100;
	$opacity = min(100, max(0, $opacity));

	// apply watermark's opacity
	if ($opacity != 100) {
		$tmp = imageCreateTrueColor($wmark[1], $wmark[2]);

		// copy relevant section from background
    	imageCopy($tmp, $targ_img, 0, 0, $x, $y, $wmark[1], $wmark[2]);

    	// copy relevant section from watermark
    	imagecopy($tmp, $wmark[0], 0, 0, 0, 0, $wmark[1], $wmark[2]);

    	imageDestroy($wmark[0]);

		$wmark[0] = $tmp;
	}

	// copy watermark with opacity
	imageCopyMerge($targ_img, $wmark[0], $x, $y, 0, 0, $wmark[1], $wmark[2], $opacity);

	imageDestroy($wmark[0]);
}


// apply gray scale
if (strpos($p['params'], "g") !== false) {
	imageFilter($targ_img, IMG_FILTER_GRAYSCALE);
}

imageInterlace($targ_img, 1);

// apply new extension
if ($p['newext'] != '') {
	$newname .= ".". $p['ext'];
	
	switch ($p['newext']) {
	case 'png':
		$info[2] = IMAGETYPE_PNG;
		break;

	case 'gif':
		$info[2] = IMAGETYPE_GIF;
		break;

	case 'jpg':
	default:
		$info[2] = IMAGETYPE_JPEG;
	}
}

// write image
switch ($info[2]) {
case IMAGETYPE_PNG:
	header('Content-type: image/png');
	$newname .= ".png";

	if ($conf && isset($conf['output']['compression'])) {
		$q = $conf['output']['compression'];
	} else {
		$q = 5;
	}

	imagePNG($targ_img, $newname, $q);
	break;

case IMAGETYPE_GIF:
	header('Content-type: image/gif');
	$newname .= ".gif";
	imageGIF($targ_img, $newname);
	break;

case IMAGETYPE_JPEG:
default:
	header('Content-type: image/jpeg');
	$newname .= ".jpg";

	if ($conf && isset($conf['output']['quality'])) {
		$q = $conf['output']['quality'];
	} else {
		$q = 95;
	}

	imageJPEG($targ_img, $newname, $q);
}


imageDestroy($src_img);
imageDestroy($targ_img);

// out to browser
$f = fopen($newname, "r");
fpassthru($f);

// at next time browser will get saved image, so this script will be called only once per configuration
