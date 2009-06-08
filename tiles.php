<?php
/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <gohr@cosmocode.de>
 */

if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../../').'/');
define('DOKU_DISABLE_GZIP_OUTPUT', 1);
require_once(DOKU_INC.'inc/init.php');
require_once(DOKU_INC.'inc/common.php');
require_once(DOKU_INC.'inc/pageutils.php');
require_once(DOKU_INC.'inc/httputils.php');
require_once(DOKU_INC.'inc/auth.php');
session_write_close();

    $data = array();
    // get parameters
    list($data['zoom'],$data['col'],$data['row']) = explode('-',$_GET['tile']);
    $data['id']    = cleanID($_GET['image']);
    $data['file']  = mediaFN($data['id']);
    $data['mtime'] = @filemtime($data['file']);

    // check auth and existance
    if(auth_quickaclcheck(getNS($data['id']).':X') < AUTH_READ) gfx_error('noauth');
    if(!$data['mtime']) gfx_error('notfound');

    // calculate zoom level scaling
    $data['ts']    = 256;
    list($data['width'],$data['height']) = getimagesize($data['file']);
    $data['scale'] = (int)pow(2,$data['zoom']);
    $data['max']   = max($data['width'],$data['height']);
    $data['inv']   = $data['max']  / ($data['ts'] * $data['scale']);
    if($data['inv'] < 1.0) gfx_error('maxzoom');

    // calculate tile boundaries
    $data['tlx']   = (int) ($data['col'] * $data['ts'] * $data['inv']);
    $data['tly']   = (int) ($data['row'] * $data['ts'] * $data['inv']);
    $data['brx']   = (int) ($data['tlx'] + ($data['ts'] * $data['inv']));
    $data['bry']   = (int) ($data['tly'] + ($data['ts'] * $data['inv']));
    if($data['tlx'] > $data['width'] || $data['tly'] > $data['height']) gfx_error('blank');

    // cache times
    $data['cache']  = getCacheName($data['file'],'.pv.'.$data['zoom'].'-'.$data['col'].'-'.$data['row'].'.jpg');
    $data['cachet'] = @filemtime($data['cache']);
    $data['selft']  = filemtime(__FILE__);

    // (re)generate
    if( ($data['cachet'] < $data['mtime']) || ($data['cachet'] < $data['selft']) ){
        if($conf['im_convert']){
            tile_im($data);
        }else{
            tile_gd($data);
        }
    }

    // send
    header('Content-type: image/jpeg');
    http_conditionalRequest(max($data['mtime'],$data['selft']));
    http_sendfile($data['cache']);
    readfile($data['cache']);




/* --------------- functions -------------------- */


function tile_gd($d){
    global $conf;

    $img = null;
    if(preg_match('/\.jpe?g$/',$d['file'])){
        $img   = @imagecreatefromjpeg($d['file']);
    }elseif(preg_match('/\.png$/',$d['file'])){
        $img   = @imagecreatefrompng($d['file']);
    }elseif(preg_match('/\.gif$/',$d['file'])){
        $img   = @imagecreatefromgif($d['file']);
    }
    if(!$img) gfx_error('generic');

    $crop  = image_crop($img,$d['width'],$d['height'],$d['tlx'],$d['tly'],$d['brx'],$d['bry']);
    imagedestroy($img);

    $scale = image_scale($crop,abs($d['brx'] - $d['tlx']),abs($d['bry'] - $d['tly']),$d['ts'],$d['ts']);
    imagedestroy($crop);

    imagejpeg($scale,$d['cache'],$conf['jpg_quality']);
    imagedestroy($scale);

    if($conf['fperm']) chmod($d['cache'], $conf['fperm']);
}

function tile_im($d){
    global $conf;

    $cmd  = $conf['im_convert'];
    $cmd .= ' '.escapeshellarg($d['file']);
    $cmd .= ' -crop \''.abs($d['brx'] - $d['tlx']).'x'.abs($d['bry'] - $d['tly']).'!+'.$d['tlx'].'+'.$d['tly'].'\'';
    $cmd .= ' -background black';
    $cmd .= ' -extent \''.abs($d['brx'] - $d['tlx']).'x'.abs($d['bry'] - $d['tly']).'!\'';
    $cmd .= ' -resize \''.$d['ts'].'x'.$d['ts'].'!\'';

    $cmd .= ' -quality '.$conf['jpg_quality'];
    $cmd .= ' '.escapeshellarg($d['cache']);

#    dbg($cmd); exit;

    @exec($cmd,$out,$retval);
    if ($retval == 0) return true;
    gfx_error('generic');
}



function image_scale($image,$x,$y,$w,$h){
    $scale=imagecreatetruecolor($w,$h);
    imagecopyresampled($scale,$image,0,0,0,0,$w,$h,$x,$y);
    return $scale;
}

function image_crop($image,$x,$y,$left,$upper,$right,$lower) {
    $w=abs($right-$left);
    $h=abs($lower-$upper);
    $crop = imagecreatetruecolor($w,$h);
    imagecopy($crop,$image,0,0,$left,$upper,$w,$h);
    return $crop;
}

function gfx_error($type){
    $file = dirname(__FILE__).'/gfx/'.$type.'.gif';
    $time = filemtime($file);
    header('Content-type: image/gif');

    http_conditionalRequest($time);
    http_sendfile($file);
    readfile($file);
    exit;
}

//Setup VIM: ex: et ts=4 enc=utf-8 :
