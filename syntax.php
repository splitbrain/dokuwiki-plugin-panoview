<?php
/**
 * Embed an image gallery
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <andi@splitbrain.org>
 */

if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../').'/');
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');
require_once(DOKU_INC.'inc/search.php');
require_once(DOKU_INC.'inc/JpegMeta.php');

class syntax_plugin_panoview extends DokuWiki_Syntax_Plugin {
    /**
     * return some info
     */
    function getInfo(){
        return confToHash(dirname(__FILE__).'/info.txt');
    }

    /**
     * What kind of syntax are we?
     */
    function getType(){
        return 'substition';
    }

    /**
     * What about paragraphs?
     */
    function getPType(){
        return 'block';
    }

    /**
     * Where to sort in?
     */
    function getSort(){
        return 301;
    }


    /**
     * Connect pattern to lexer
     */
    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('\{\{panoview>[^}]*\}\}',$mode,'plugin_panoview');
    }

    /**
     * Handle the match
     */
    function handle($match, $state, $pos, &$handler){
        global $ID;

        $data = array(
            'width'         => 500,
            'height'        => 250,
            'align'         => 0,
            'initialZoom'   => 2,
            'tileBaseUri'   => DOKU_BASE.'lib/plugins/panoview/tiles.php',
            'tileSize'      => 256,
            'maxZoom'       => 10,
            'blankTile'     => DOKU_BASE.'lib/plugins/panoview/gfx/blank.gif',
            'loadingTile'   => DOKU_BASE.'lib/plugins/panoview/gfx/progress.gif',
        );

        $match = substr($match,11,-2); //strip markup from start and end

        // alignment
        $data['align'] = 0;
        if(substr($match,0,1) == ' ') $data['align'] += 1;
        if(substr($match,-1,1) == ' ') $data['align'] += 2;

        // extract params
        list($img,$params) = explode('?',$match,2);
        $img = trim($img);

        // resolving relatives
        $data['image'] = resolve_id(getNS($ID),$img);

        $file = mediaFN($data['image']);
        list($data['imageWidth'],$data['imageHeight']) = @getimagesize($file);

        // size
        if(preg_match('/^(\d+)[xX](\d+)$/',$params,$match)){
            $data['width']  = $match[1];
            $data['height'] = $match[2];
        }

        return $data;
    }

    /**
     * Create output
     */
    function render($mode, &$R, $data) {
        if($mode != 'xhtml') return false;

        require_once(DOKU_INC.'inc/JSON.php');
        $json = new JSON();

        $img = '<a href="'.ml($data['image']).'"><img src="'.
                    ml($data['image'], array('w'=>$data['width'],'h'=>$data['height'])).'" width="'.
                    $data['width'].'" height="'.$data['height'].'" alt="" /></a>';

        if($data['align'] == 1){
            $align = 'medialeft';
        }elseif($data['align'] == 2){
            $align = 'mediaright';
        }else{
            $align = 'mediacenter';
        }


        $R->doc .= '
            <div class="panoview_plugin '.$align.'" style="width: '.$data['width'].'px; height: '.$data['height'].'px;">
              <div class="well"><!-- --></div>
              <div class="surface">'.$img.'</div>
              <p class="controls" style="display: none">
                <span class="zoomIn" title="Zoom In">+</span>
                <span class="zoomOut" title="Zoom Out">-</span>
              </p>
                <div class="options" style="display:none">'.hsc($json->encode($data)).'</div>
            </div>
        ';


        return true;
    }


}

//Setup VIM: ex: et ts=4 enc=utf-8 :
