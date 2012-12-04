<?php
/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <gohr@cosmocode.de>
 */

if(!defined('DOKU_INC')) define('DOKU_INC', realpath(dirname(__FILE__).'/../../../').'/');
define('DOKU_DISABLE_GZIP_OUTPUT', 1);
require_once(DOKU_INC.'inc/init.php');
require_once(DOKU_INC.'inc/common.php');
require_once(DOKU_INC.'inc/pageutils.php');
require_once(DOKU_INC.'inc/httputils.php');
require_once(DOKU_INC.'inc/pluginutils.php');
require_once(DOKU_INC.'inc/auth.php');
session_write_close();

/** @var syntax_plugin_panoview $pl */
$pl =& plugin_load('syntax', 'panoview');

$data = array();
// get parameters
list($data['zoom'], $data['col'], $data['row']) = explode('-', $_GET['tile']);
$data['id']    = cleanID($_GET['image']);
$data['file']  = mediaFN($data['id']);
$data['mtime'] = @filemtime($data['file']);

// check auth and existance
if(auth_quickaclcheck(getNS($data['id']).':X') < AUTH_READ) $pl->gfx_error('noauth');
if(!$data['mtime']) $pl->gfx_error('notfound');

// calculate zoom level scaling
$data['ts'] = 256;
list($data['width'], $data['height']) = getimagesize($data['file']);
$data['scale'] = (int) pow(2, $data['zoom']);
$data['max']   = max($data['width'], $data['height']);
$data['inv']   = $data['max'] / ($data['ts'] * $data['scale']);

if($data['inv'] < 0.5) $pl->gfx_error('maxzoom');
if($data['inv'] < 1.0) $data['inv'] = 1.0; // original size, no upscaling

// calculate tile boundaries
$data['tlx'] = (int) ($data['col'] * $data['ts'] * $data['inv']);
$data['tly'] = (int) ($data['row'] * $data['ts'] * $data['inv']);
$data['brx'] = (int) ($data['tlx'] + ($data['ts'] * $data['inv']));
$data['bry'] = (int) ($data['tly'] + ($data['ts'] * $data['inv']));
if($data['tlx'] > $data['width'] || $data['tly'] > $data['height']) $pl->gfx_error('blank');

// cache times
$data['cache']  = getCacheName($data['file'], '.pv.'.$data['zoom'].'-'.$data['col'].'-'.$data['row'].'.jpg');
$data['cachet'] = @filemtime($data['cache']);

// (re)generate
if($data['cachet'] < $data['mtime']) {
    $pl->tile_lock($data);
    if($conf['im_convert']) {
        $pl->tile_im($data);
    } else {
        $pl->tile_gd($data);
    }
    $pl->tile_unlock($data);
}

// send
header('Content-type: image/jpeg');
http_conditionalRequest(max($data['mtime'], $data['selft']));

//use x-sendfile header to pass the delivery to compatible webservers
if(http_sendfile($data['cache'])) exit;

// send file contents
$fp = @fopen($data['cache'], "rb");
if($fp) {
    http_rangeRequest($fp, filesize($data['cache']), 'image/jpeg');
} else {
    header("HTTP/1.0 500 Internal Server Error");
    print "Could not read tile - bad permissions?";
}


//Setup VIM: ex: et ts=4 enc=utf-8 :
