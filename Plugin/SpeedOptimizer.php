<?php
/**
 * @Author: nguyen
 * @Date:   2020-02-12 14:01:01
 * @Last Modified by:   Alex Dong
 * @Last Modified time: 2021-06-16 15:12:06
 */

namespace Magepow\SpeedOptimizer\Plugin;

use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Design\Theme\ThemeProviderInterface;
use Magento\Framework\App\Response\Http;
use Magepow\SpeedOptimizer\Helper\Data;

class SpeedOptimizer extends \Magento\Framework\View\Element\Template
{
    protected $request;

    protected $helper;

    protected $content;

    protected $isJson;

    protected $exclude = [];

    protected $excludeHtml = [];

    protected $scripts = [];

    protected $storeManager;

    protected $themeProvider;

    protected $placeholder = 'data:image/svg+xml;charset=utf-8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22' . '$width' . '%22%20height%3D%22' . '$height' . '%22%20viewBox%3D%220%200%20225%20265%22%3E%3C%2Fsvg%3E';

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        RequestInterface $request,
        Data $helper,
        StoreManagerInterface $storeManager,
        ThemeProviderInterface $themeProvider,
        array $data = []
    ) 
    {    
        parent::__construct($context, $data);
        $this->request  = $request;
        $this->helper   = $helper;
        $this->storeManager  = $storeManager;
        $this->themeProvider =  $themeProvider;

    }

    /**
     * @param Http $subject
     * @return void
     */
    public function beforeSendResponse(Http $response)
    {
        if( !$this->helper->getConfigModule('general/enabled') ) return;
        
        if($this->request->isXmlHttpRequest()){
            /* request is ajax */
            $lazyAjax = $this->helper->getConfigModule('general/lazy_ajax');
            if( !$lazyAjax ) return;
            $contentType = $response->getHeader('Content-Type');
            if( $contentType && $contentType->getMediaType() == 'application/json' ) {
                $this->isJson = true;
                // return; // break response type json
            }
        }

        $body = $response->getBody();
        $body = $this->addLoading($body);
        $body_includes = $this->helper->getConfigModule('general/body_includes');
        if($body_includes) $body = $this->addToBottomBody($body, $body_includes);

        $minifyHtml    = $this->helper->getConfigModule('general/minify_html');
        $minifyJs    = $this->helper->getConfigModule('general/minify_js');
        $deferJs       = $this->helper->getConfigModule('general/defer_js');

        $body = $this->processExcludeJs($body, $minifyJs, $deferJs);
        // $body = $this->getHtmlClean($body);
        if($minifyHtml) $body = $this->minifyHtml($body);

        $bodyClass   = '';
        $loadingBody = $this->helper->getConfigModule('general/loading_body');
        if($loadingBody){
            $bodyClass .= ' loading_body';
            $body       = $this->addToTopBody($body, '<div class="preloading"><div class="loading"></div></div>'); 
        }

        $loadingImg  = $this->helper->getConfigModule('general/loading_img');
        if($loadingImg){
            $bodyClass .= ' loading_img';   
            $exclude = $this->helper->getConfigModule('general/exclude_img');
            // $exclude = 'product-image-photo';
            if($exclude){
                $exclude = str_replace(' ', '', $exclude);
                $this->exclude = explode(',', $exclude);
            }
            $placeholder = $this->helper->getConfigModule('general/placeholder');
            // $placeholder = false;
            $regex_block = $this->helper->getConfigModule('general/regex_block');
            // $regex_block = '';
            $body = $this->addLazyload($body, $placeholder, $regex_block );             
        }

        $body = $this->addBodyClass($body, $bodyClass);     

        if ($deferJs){
            $scripts = implode('', $this->scripts);
            $body    = $this->addToBottomBody($body, $scripts);
        } else {
            $body = preg_replace_callback(
                '~<\s*\bscript\b[^>]*>(.*?)<\s*\/\s*script\s*>~is',
                function($match){
                    $scriptId = trim($match[1], ' ');
                    if($scriptId && isset($this->scripts[$scriptId])){
                        return $this->scripts[$scriptId];
                    }else {
                        return $match[0];
                    }
                },
                $body
            );
        }

        $response->setBody($body);
    }

    public function addBodyClass( $content, $class )
    {
        // return preg_replace( '/<body([\s\S]*?)(?:class="(.*?)")([\s\S]*?)?([^>]*)>/', sprintf( '<body${1}class="%s ${2}"${3}>', $class ), $content );
        return preg_replace_callback(
            '/<body([\s\S]*?)(?:class="(.*?)")([\s\S]*?)?([^>]*)>/',
            function($match) use ($class) {
                if($match[2]){
                    return $lazy = str_replace('class="', 'class="' . $class . ' ', $match[0]); 
                }else {
                    return str_replace('<body ', '<body class="' . $class . '" ', $match[0]);
                }
            },
            $content
        );  
    }

    public function addLazyload( $content, $placeholder=false, $start=0 )
    {
        if($start && !is_numeric($start)) $start = strpos($content, $start);
        $html = '';
        if( $start ){
            $page = str_split($content, $start);
            $isFirst = true;
 
            foreach ($page as $key => $pg) {
                if(!$key){
                    $html .= $pg;
                }else {
                    if($placeholder) $pg = $this->addLazyloadPlaceholder($pg);
                    $html .= $this->addLazyloadAll($pg);
                }
                
            }
        }else {
            if($placeholder) $content = $this->addLazyloadPlaceholder($content);
            $html .= $this->addLazyloadAll($content);           
        }

        return $html;
    }

    /* Placeholder so keep layout original while loading */
    public function addLazyloadPlaceholder( $content, $addJs=false ) 
    {
        $placeholder = $this->placeholder;

        $content = preg_replace_callback_array(
            [
                '/<img([^>]+?)width=[\'"]?([^\'"\s>]+)[\'"]([^>]+?)height=[\'"]?([^\'"\s>]+)[\'"]?([^>]*)>/' => function ($match) use ($placeholder) {
                    $holder = str_replace(['$width', '$height'], [$match[2], $match[4]], $placeholder);
                    return $this->addLazyloadImage($match[0], $holder);
                },
                '/<img([^>]+?)height=[\'"]?([^\'"\s>]+)[\'"]([^>]+?)width=[\'"]?([^\'"\s>]+)[\'"]?([^>]*)>/' => function ($match) use ($placeholder) {
                    $holder = str_replace(['$width', '$height'], [$match[4], $match[2]], $placeholder);
                    return $this->addLazyloadImage($match[0], $holder);
                }
            ],
            $content
        );

        if($addJs) $content = $this->addLazyLoadJs($content);

        return $content;
    }

    public function isExclude($class)
    {
        if(is_string($class)) $class = explode(' ', $class);
        $excludeExist = array_intersect($this->exclude, $class);
        return !empty($excludeExist);
    }

    public function addLazyloadImage($content, $placeholder)
    {
        if($this->isJson) return  $this->addLazyloadImageJson($content, $placeholder);
        return preg_replace_callback(
            '/<img\s*.*?(?:class="(.*?)")?([^>]*)>/',
            function($match) use ($placeholder) {

                if(stripos($match[0], ' data-src="') !== false) return $match[0];
                if(stripos($match[0], ' class="') !== false){
                    if( $this->isExclude($match[1]) ) return $match[0];
                    $lazy = str_replace(' class="', ' class="lazyload ', $match[0]); 
                    if(stripos($match[1], 'background-image') !== false){
                        $lazy = str_replace('<img ', '<source ', $lazy); 
                    }
                }else {
                    $lazy = str_replace('<img ', '<img class="lazyload" ', $match[0]);
                    if(stripos($match[1], 'background-image') !== false){
                        $lazy = str_replace('<img ', '<source ', $lazy); 
                    }
                }

                /* break if exist data-src */
                // if(strpos($lazy, ' data-src="')) return $lazy;

                return str_replace(' src="', ' src="' .$placeholder. '" data-src="', $lazy);
            },
            $content
        );        
    }

    public function addLazyloadImageJson($content, $placeholder)
    {
        $placeholder = addslashes($placeholder); 
        return preg_replace_callback(
            '/<img\s*.*?(?:class=\\\"(.*?)\\\")?([^>]*)>/',
            function($match) use ($placeholder) {
                
                if(stripos($match[0], ' data-src=\"') !== false) return $match[0];
                if(stripos($match[0], ' class="') !== false){
                    if( $this->isExclude($match[1]) ) return $match[0];
                    $lazy = str_replace(' class=\"', ' class=\"lazyload ', $match[0]); 
                }else {
                    $lazy = str_replace('<img ', '<img class=\"lazyload\" ', $match[0]);
                }

                /* break if exist data-src */
                // if(strpos($lazy, ' data-src=\"')) return $lazy;

                return str_replace(' src=\"', ' src=\"' . $placeholder . '\" data-src=\"', $lazy);
            },
            $content
        );        
    }

    /* Not Placeholder so can't keep layout original while loading */
    public function addLazyloadAll( $content, $addJs=false ) 
    {
        $placeholder = str_replace(['$width', '$height'], [1, 1], $this->placeholder);

        $content = $this->addLazyloadImage($content, $placeholder);

        if($addJs) $content = $this->addLazyLoadJs($content);

        return $content;
    }

    /* Insert to Top body */
    public function addToTopBody( $content, $insert)
    {
        return $content = preg_replace_callback(
            '/<body([\s\S]*?)?([^>]*)>/',
            function($match) use ($insert) {
                return $match[0] . $insert;
            },
            $content
        );      
    }

    /* Insert to Bottom body */
    public function addToBottomBody( $content, $insert)
    {
        $content = str_ireplace('</body>', $insert . '</body>', $content);
        return $content;         
    }

    /* Add js to content */
    public function addLazyLoadJs( $content, $selector='img', $exclude='.loaded' )
    {
        if($this->exclude) $exclude = '.' . implode(', .', $this->exclude);
        $script = '<script type="text/javascript"> require(["jquery", "magepow/lazyload", "domReady!"], function($, lazyload){$(' . $selector .').not("' . $exclude . '").lazyload();});</script>';
        return $this->addToBottomBody($content, $script);
    }

    public function processExcludeJs($content, $minify=false, $deferJs=false)
    {
        $content = preg_replace_callback(
            '~<\s*\bscript\b[^>]*>(.*?)<\s*\/\s*script\s*>~is',
            function($match) use($minify, $deferJs){
                // if(stripos($match[0], 'type="text/x-magento') !== false) return $match[0];
                $scriptId = 'script_' . uniqid();
                if ($minify && trim($match[1], ' ')){
                    $this->scripts[$scriptId] =  $this->minifyJs( $match[0] );
                }else {
                    $this->scripts[$scriptId] = $match[0];
                }
                if (!$deferJs) return '<script>' . $scriptId . '</script>';
                return '';
            },
            $content
        );

        return $content;
    }

    public function minifyJs($script)
    {
        $regex   = '~//?\s*\*[\s\S]*?\*\s*//?~'; // RegEx to remove /** */ and // ** **// php comments
        $search = array(
            '/(?:(?:\/\*(?:[^*]|(?:\*+[^*\/]))*\*+\/)|(?:(?<!\:|\\\|\') \/\/.*))/',
            '/(\s)+/s',         // shorten multiple whitespace sequences
        );

        $replace = array(
            '',
            '\\1',
        );
        $minScript = preg_replace($search, $replace, $script);
        /* Return $script when $minScript empty */
        return $minScript ? $minScript : $script;
    }

    public function minifyHtml($content) 
    {
        $minHtml = $content;

        // Searching textarea and pre
        preg_match_all('#\<textarea.*\>.*\<\/textarea\>#Uis', $minHtml, $foundTxt);
        preg_match_all('#\<pre.*\>.*\<\/pre\>#Uis', $minHtml, $foundPre);

        // replacing both with <textarea>$index</textarea> / <pre>$index</pre>
        $minHtml = str_replace($foundTxt[0], array_map(function($el){ return '<textarea>'.$el.'</textarea>'; }, array_keys($foundTxt[0])), $minHtml);
        $minHtml = str_replace($foundPre[0], array_map(function($el){ return '<pre>'.$el.'</pre>'; }, array_keys($foundPre[0])), $minHtml);

        $search = array(
            '/\>[^\S ]+/s',     // strip whitespaces after tags, except space
            '/[^\S ]+\</s',     // strip whitespaces before tags, except space
            '/(\s)+/s',         // shorten multiple whitespace sequences
            // '/<!--((?! ko | \/ko )[\s\S])*?-->/' // remove comment exclude knockoutJS
            // '/<!--(.|\s)*?-->/' // Remove HTML comments this cause error knockoutJS
        );

        $replace = array(
            '>',
            '<',
            '\\1',
            // '',
            // ''
        );

        $minHtml = preg_replace($search, $replace, $minHtml);

        // Replacing back with content
        $minHtml = str_replace(array_map(function($el){ return '<textarea>'.$el.'</textarea>'; }, array_keys($foundTxt[0])), $foundTxt[0], $minHtml);
        $minHtml = str_replace(array_map(function($el){ return '<pre>'.$el.'</pre>'; }, array_keys($foundPre[0])), $foundPre[0], $minHtml);

        /* Return $content when $minHtml empty */
        return $minHtml ? $minHtml : $content;
    }

    public function addLoading($content)
    {
        $style     = '';
        $preload   = '';
        $mediaUrl  = $this ->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA );
        $staticUrl =  $this->escapeUrl(
                $this->getViewFileUrl('', [
                    'area'  => 'frontend',
                    'theme' => $this->getTheme()->getThemePath()
                ])
            );
        $placeholder = $staticUrl . '/images/loader-1.gif';

        $jsTranslation =  $staticUrl . '/js-translation.json';
        $favicon = $staticUrl . '/favicon.ico';

        // $preload .= '<link rel="preload" type="text/json" crossorigin="anonymous" href="' . $jsTranslation . '"></>';
        // $preload .= '<link rel="preload" as="icon" crossorigin="anonymous" href="' . $placeholder . '"></>';
        // $preload .= '<link rel="preload" as="icon" crossorigin="anonymous" href="' . $favicon . '"></>';
        $loadingBody = $this->helper->getConfigModule('general/loading_body_placeholder');

        $loadingBody = $loadingBody ? $mediaUrl . 'magepow/speedoptimizer/' . $loadingBody : $placeholder;
        $style   .= 'body.loading_body .preloading .loading{background-image: url("' . $loadingBody . '")}';

        $loadingImg = $this->helper->getConfigModule('general/loading_img_placeholder');
        $loadingImg  = $loadingImg ?  $mediaUrl . 'magepow/speedoptimizer/' . $loadingImg : $placeholder;


        $style .= 'body.loading_img .lazyload{background-image: url("' . $loadingImg . '")}';
        $style .= 'body.loading_img img.loaded{background-image: none}';    

        if($style){
            $style = '<style type="text/css">' . $style . '</style>';
            $content = str_ireplace('</head>', $style . $preload . '</head>', $content);
        }

        return $content;
    }

    public function getTheme()
    {
        $themeId = $this->_scopeConfig->getValue(
            \Magento\Framework\View\DesignInterface::XML_PATH_THEME_ID,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $this->_storeManager->getStore()->getId()
        );

        /** @var $theme \Magento\Framework\View\Design\ThemeInterface */
        return $this->themeProvider->getThemeById($themeId);
    }

    public function getHtmlClean($html)
    {
        /* break process if html have many tag body */
        if( preg_match_all("/<body.*\/body>/s", $html, $matches) > 1) return $html;

        /* break process if html have many tag body */

        if($this->isMobile()){
            $excludeHtml = $this->helper->getConfigModule('general/exclude_html_mobile');
        } else if($this->isTablet()) {
            $excludeHtml = $this->helper->getConfigModule('general/exclude_html_tablet');
        } else {
            $excludeHtml = $this->helper->getConfigModule('general/exclude_html_desktop');
        }
        if(!$excludeHtml) return $html;

        $excludeHtml = str_replace(' ', '', $excludeHtml);
        $this->excludeHtml = explode(',', $excludeHtml);      
        $html = $this->cleanHtml($html, "~<\s*\bheader\b[^>]*>(.*?)<\s*\/\s*header\s*>~is");
        $html = $this->cleanHtml($html, "~<\s*\bmain\b[^>]*>(.*?)<\s*\/\s*main\s*>~is");

        return $html;
    }

    public function cleanHtml($html, $regex)
    {
        $content = preg_replace_callback( $regex, function($match){
                $dom = new \DOMDocument;
                $dom->preserveWhiteSpace = false;
                libxml_use_internal_errors(true);
                $dom->loadHTML($match[0], LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                $xp = new \DOMXPath($dom);
                $excludeHtml = $this->excludeHtml;
                if($excludeHtml){
                    foreach ($excludeHtml as $exclude) {
                        // $exclude = '//*[@data-exclude_html_desktop="1"]';
                        $items = $xp->query($exclude);
                        if(is_array($items) || is_object($items)){
                            foreach ($items as $item) {
                                if($item) $item->parentNode->removeChild($item);
                            }
                        }
                    }
                }
                $newHtml =  $dom->saveHTML($dom->documentElement);
                $newHtml = trim($newHtml, ' ');
                return $newHtml ? $newHtml : $match[0];
            },
            $html
        );

        return $content ? $content : $html;
    }

    public function isTablet()
    {
        $useragent=$_SERVER['HTTP_USER_AGENT'];
        return preg_match('/iPad|iPad.*Mobile/i', $useragent);        
    }

    public function isMobile()
    {
        $useragent=$_SERVER['HTTP_USER_AGENT'];
        return preg_match('/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows (ce|phone)|xda|xiino/i',$useragent)
        ||preg_match('/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i',substr($useragent,0,4));

    }

}
