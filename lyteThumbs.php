<?php
/*
 * simple proxy for YouTube images
 * 
 * @param string origThumbUrl
 * @return image
 * 
 * assumption 1: thumbnails are served from known domain ("ytimg.com","youtube.com","youtu.be")
 * assumption 2: thumbnails are always jpg
 * 
 */

// no error reporting, those break header() output
error_reporting(0);

/* 
 * step 0: set constant for dir where thumbs are stored + declaring some variables
 */

if ( ! defined( 'LYTE_CACHE_DIR' ) ) {
    define( 'WP_CONTENT_DIR', dirname( dirname( __DIR__ ) ) );
    define( 'LYTE_CACHE_CHILD_DIR', 'cache/lyteThumbs' );
    define( 'LYTE_CACHE_DIR', WP_CONTENT_DIR .'/'. LYTE_CACHE_CHILD_DIR );
}

$lyte_thumb_error = "";
$lyte_thumb_dontsave = "";
$thumbContents = "";
$lyte_thumb_report_err = false;

/*
 * step 1: get vid ID (or full thumbnail URL) from request and validate
 */

// should we output debug info in a header?
if ( array_key_exists("reportErr", $_GET) ) {
    $lyte_thumb_report_err = true;
}

// get thumbnail-url from request
if ( array_key_exists("origThumbUrl", $_GET) && $_GET["origThumbUrl"] !== "" ) {
    $origThumbURL = urldecode($_GET["origThumbUrl"]);
} else {
    // faulty request, force a grey background
    $origThumbURL = "https://i.ytimg.com/vi/thisisnotavalidvid/hqdefault.jpg";
}

// make sure the thumbnail-url is for youtube
$origThumbDomain = parse_url($origThumbURL, PHP_URL_HOST);
if ( str_replace( array("ytimg.com","youtube.com","youtu.be"), "", $origThumbDomain ) === $origThumbDomain ) {
    // faulty request, force a grey background
    $origThumbURL = "https://i.ytimg.com/vi/thisisnotavalidvid/hqdefault.jpg";
}

// make sure the thumbnail-url is for an image (.jpg)
$origThumbPath = parse_url($origThumbURL, PHP_URL_PATH);
if ( lyte_str_ends_in( $origThumbPath, ".jpg" ) !== true ) {
    // faulty request, force a grey background
    $origThumbURL = "https://i.ytimg.com/vi/thisisnotavalidvid/hqdefault.jpg";
}

// TODO: extra checks to prevent automated hotlinking abuse?

/*
 * step 2: check for and if need be create wp-content/cache/lyte_thumbs
 */

if ( lyte_check_cache_dir(LYTE_CACHE_DIR) === false ) {
    $lyte_thumb_dontsave = true;
    $lyte_thumb_error .= "checkcache fail/ ";
}

/* 
 * step 3: if not in cache: fetch from YT and store in cache
 */

if ( strpos($origThumbURL,'http') !== 0 && strpos($origThumbURL,'//') === 0 ) {
    $origThumbURL = 'https:'.$origThumbURL;
}

$localThumb = LYTE_CACHE_DIR . '/' . md5($origThumbURL) . ".jpg";

if ( !file_exists($localThumb) || $lyte_thumb_dontsave ) {
    $thumbContents = lyte_get_thumb($origThumbURL);
    
    if ( $thumbContents != "" && !$lyte_thumb_dontsave ) {
        file_put_contents($localThumb, $thumbContents);
    }
}

/*
 * step 4: serve img
 */

if ( $thumbContents == "" && !$lyte_thumb_dontsave && file_exists($localThumb) && mime_content_type($localThumb) === "image/jpeg" ) {
    $thumbContents = file_get_contents( $localThumb );
} else {
    $lyte_thumb_error .= "not from cache/ ";
}

if ( $thumbContents != "") {
    if ( $lyte_thumb_error !== "" && $lyte_thumb_report_err ) {
        header('X-lyte-error:  '.$lyte_thumb_error);
    }

    $modTime=filemtime($localThumb);

    date_default_timezone_set("UTC");
    $modTimeMatch = (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) === $modTime);

    if ( $modTimeMatch ) {
        header('HTTP/1.1 304 Not Modified');
        header('Connection: close');
    } else {
        // send all sorts of headers
        $expireTime=60*60*24*7; // 1w
        header('Content-Length: '.strlen($thumbContents));
        header('Cache-Control: max-age='.$expireTime.', public, immutable');
        header('Expires: '.gmdate('D, d M Y H:i:s', time() + $expireTime).' GMT');
        header('Last-Modified: '.gmdate('D, d M Y H:i:s', $modTime).' GMT');
        header('Content-type:image/jpeg');
        echo $thumbContents;
    }
} else {
    $lyte_thumb_error .= "no thumbContent/ ";
    lyte_thumb_fallback();
}

/*
 * helper functions
 */

function lyte_check_cache_dir( $dir ) {
    // Try creating the dir if it doesn't exist.
    if ( ! file_exists( $dir ) ) {
        @mkdir( $dir, 0775, true );
        if ( ! file_exists( $dir ) ) {
            return false;
        }
    }

    // If we still cannot write, bail.
    if ( ! is_writable( $dir ) ) {
        return false;
    }

    // Create an index.html in there to avoid prying eyes!
    $idx_file = rtrim( $dir, '/\\' ) . '/index.html';
    if ( ! is_file( $idx_file ) ) {
        @file_put_contents( $idx_file, '<html><head><meta name="robots" content="noindex, nofollow"></head><body>Generated by <a href="http://wordpress.org/extend/plugins/wp-youtube-lyte/" rel="nofollow">WP YouTube Lyte</a></body></html>' );
    }

    return true;
}

function lyte_str_ends_in($haystack,$needle) {
    $needleLength = strlen($needle);
    $haystackLength = strlen($haystack);
    $lastPos=strrpos($haystack,$needle);
    if ($lastPos === $haystackLength - $needleLength) {
        return true;
    } else {
        return false;
    }
}

function lyte_get_thumb($thumbUrl) {
    global $lyte_thumb_error;
    if (function_exists("curl_init")) {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $thumbUrl);
        curl_setopt($curl, CURLOPT_USERAGENT, "Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 5.2; .NET CLR 1.1.4322; .NET CLR 2.0.50727)");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
        $str = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
        if ( !$err && $str != "" ) {
            return $str;
        } else {
            $lyte_thumb_error .= "curl err: ".$err."/ ";
        }
    } else {
        $lyte_thumb_error .= "no curl/ ";
    }

    // if no curl or if curl error
    $resp = file_get_contents($thumbUrl);
    return $resp;
}

function lyte_thumb_fallback() {
    global $origThumbURL, $lyte_thumb_error, $lyte_thumb_report_err;
    // if for any reason we can't show a local thumbnail, we redirect to the original one
    if ( strpos( $origThumbURL, "http" ) !== 0) {
            $origThumbURL = "https:".$origThumbURL;              
    }
    if ( $lyte_thumb_report_err ) {
        header('X-lyte-error:  '.$lyte_thumb_error);
    }
    header('HTTP/1.1 301 Moved Permanently');
    header('Location:  '.  $origThumbURL );
}
