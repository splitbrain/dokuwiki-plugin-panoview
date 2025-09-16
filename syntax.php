<?php

use dokuwiki\Extension\SyntaxPlugin;

/**
 * Embed an image gallery
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <andi@splitbrain.org>
 */
class syntax_plugin_panoview extends SyntaxPlugin
{
    /**
     * What kind of syntax are we?
     */
    public function getType()
    {
        return 'substition';
    }

    /**
     * What about paragraphs?
     */
    public function getPType()
    {
        return 'block';
    }

    /**
     * Where to sort in?
     */
    public function getSort()
    {
        return 301;
    }

    /**
     * Connect pattern to lexer
     */
    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('\{\{panoview>[^}]*\}\}', $mode, 'plugin_panoview');
    }

    /**
     * Handle the match
     */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        global $ID;

        $data = ['width' => 500, 'height' => 250, 'align' => 0, 'initialZoom' => 1, 'tileBaseUri' => DOKU_BASE . 'lib/plugins/panoview/tiles.php', 'tileSize' => 256, 'maxZoom' => 10, 'blankTile' => DOKU_BASE . 'lib/plugins/panoview/gfx/blank.gif', 'loadingTile' => DOKU_BASE . 'lib/plugins/panoview/gfx/progress.gif'];

        $match = substr($match, 11, -2); //strip markup from start and end

        // alignment
        $data['align'] = 0;
        if (str_starts_with($match, ' ')) $data['align'] += 1;
        if (str_ends_with($match, ' ')) $data['align'] += 2;

        // extract params
        [$img, $params] = explode('?', $match, 2);
        $img = trim($img);

        // resolving relatives
        $data['image'] = resolve_id(getNS($ID), $img);

        $file = mediaFN($data['image']);
        [$data['imageWidth'], $data['imageHeight']] = @getimagesize($file);

        // calculate maximum zoom
        $data['maxZoom'] = ceil(sqrt(max($data['imageWidth'], $data['imageHeight']) / $data['tileSize']));

        // size
        if (preg_match('/\b(\d+)[xX](\d+)\b/', $params, $match)) {
            $data['width'] = $match[1];
            $data['height'] = $match[2];
        }

        // initial zoom
        if (preg_match('/\b[zZ](\d+)\b/', $params, $match)) {
            $data['initialZoom'] = $match[1];
        }
        if ($data['initialZoom'] < 0) $data['initialZoom'] = 0;
        if ($data['initialZoom'] > $data['maxZoom']) $data['initialZoom'] = $data['maxZoom'];

        return $data;
    }

    /**
     * Create output
     */
    public function render($mode, Doku_Renderer $R, $data)
    {
        if ($mode != 'xhtml') return false;
        global $ID;

        $img = '<a href="' . ml($data['image'], ['id' => $ID], false) . '"><img src="' .
            ml($data['image'], ['w' => $data['width'], 'h' => $data['height']]) . '" width="' .
            $data['width'] . '" height="' . $data['height'] . '" alt="" /></a>';

        if ($data['align'] == 1) {
            $align = 'medialeft';
        } elseif ($data['align'] == 2) {
            $align = 'mediaright';
        } else {
            $align = 'mediacenter';
        }

        $R->doc .= '
            <div class="panoview_plugin ' . $align . '" style="width: ' . $data['width'] . 'px; height: ' . $data['height'] . 'px;">
              <div class="well"><!-- --></div>
              <div class="surface">' . $img . '</div>
              <p class="controls" style="display: none">
                <span class="zoomIn" title="Zoom In">+</span>
                <span class="zoomOut" title="Zoom Out">-</span>
                <span class="maximize"><img src="' . DOKU_BASE . 'lib/plugins/panoview/gfx/window.gif" style="position: absolute; bottom: 4px; right: 5px;" title="Maximize"></span>
              </p>
                <div class="options" style="display:none">' . hsc(json_encode($data)) . '</div>
            </div>
        ';

        return true;
    }

    // ----------- Tile Generator below ---------------

    /**
     * Create a tile using libGD
     */
    public function tileGD($d)
    {
        global $conf;

        $img = null;
        if (preg_match('/\.jpe?g$/', $d['file'])) {
            $img = @imagecreatefromjpeg($d['file']);
        } elseif (preg_match('/\.png$/', $d['file'])) {
            $img = @imagecreatefrompng($d['file']);
        } elseif (preg_match('/\.gif$/', $d['file'])) {
            $img = @imagecreatefromgif($d['file']);
        }
        if ($img === null) $this->gfxError('generic');

        $crop = $this->imageCrop($img, $d['width'], $d['height'], $d['tlx'], $d['tly'], $d['brx'], $d['bry']);
        imagedestroy($img);

        $scale = $this->imageScale($crop, abs($d['brx'] - $d['tlx']), abs($d['bry'] - $d['tly']), $d['ts'], $d['ts']);
        imagedestroy($crop);

        imagejpeg($scale, $d['cache'], $conf['jpg_quality']);
        imagedestroy($scale);

        if ($conf['fperm']) chmod($d['cache'], $conf['fperm']);
    }

    /**
     * Create a tile using Image Magick
     */
    public function tileIM($d)
    {
        global $conf;

        $cmd = $this->getConf('nice');
        $cmd .= ' ' . $conf['im_convert'];
        $cmd .= ' ' . escapeshellarg($d['file']);
        $cmd .= ' -crop \'' . abs($d['brx'] - $d['tlx']) . 'x' . abs($d['bry'] - $d['tly']) .
                '!+' . $d['tlx'] . '+' . $d['tly'] . '\'';
        $cmd .= ' -background black';
        $cmd .= ' -extent \'' . abs($d['brx'] - $d['tlx']) . 'x' . abs($d['bry'] - $d['tly']) . '!\'';
        $cmd .= ' -resize \'' . $d['ts'] . 'x' . $d['ts'] . '!\'';

        $cmd .= ' -quality ' . $conf['jpg_quality'];
        $cmd .= ' ' . escapeshellarg($d['cache']);

        #    dbg($cmd); exit;

        @exec($cmd, $out, $retval);
        if ($retval == 0) return true;
        $this->gfxError('generic');
    }

    /**
     * Scale an image with libGD
     */
    public function imageScale($image, $x, $y, $w, $h)
    {
        $scale = imagecreatetruecolor($w, $h);
        imagecopyresampled($scale, $image, 0, 0, 0, 0, $w, $h, $x, $y);
        return $scale;
    }

    /**
     * Crop an image with libGD
     */
    public function imageCrop($image, $x, $y, $left, $upper, $right, $lower)
    {
        $w = abs($right - $left);
        $h = abs($lower - $upper);
        $crop = imagecreatetruecolor($w, $h);
        imagecopy($crop, $image, 0, 0, $left, $upper, $w, $h);
        return $crop;
    }

    /**
     * Send a graphical error message and stop script
     */
    public function gfxError($type)
    {
        $file = __DIR__ . '/gfx/' . $type . '.gif';
        $time = filemtime($file);
        header('Content-type: image/gif');

        http_conditionalRequest($time);
        http_sendfile($file);
        readfile($file);
        exit;
    }

    /**
     * Acquire a lock for the tile generator
     */
    public function tileLock($d)
    {
        global $conf;

        $lockDir = $conf['lockdir'] . '/' . md5($d['id']) . '.panoview';
        @ignore_user_abort(1);

        $timeStart = time();
        do {
            //waited longer than 25 seconds? -> stale lock?
            if ((time() - $timeStart) > 25) {
                if (time() - @filemtime($lockDir) > 30) $this->tileUnlock($d);
                send_redirect(
                    DOKU_URL . 'lib/plugins/panoview/tiles.php?tile=' .
                    $d['zoom'] . '-' . $d['col'] . '-' . $d['row'] . '&image=' . rawurlencode($d['id'])
                );
                exit;
            }
            $locked = @mkdir($lockDir, $conf['dmode']);
            if ($locked) {
                if (!empty($conf['dperm'])) chmod($lockDir, $conf['dperm']);
                break;
            }
            usleep(random_int(500, 3000));
        } while ($locked === false);
    }

    /**
     * Unlock the tile generator
     */
    public function tileUnlock($d)
    {
        global $conf;

        $lockDir = $conf['lockdir'] . '/' . md5($d['id']) . '.panoview';
        @rmdir($lockDir);
        @ignore_user_abort(0);
    }
}
