<?php

/**
 * Site Class
 */
class Site
{
    protected $locale;
    protected $locales;
    protected $prefix_locale;

    protected static $i18l = [];

    public function __construct(array $options = [])
    {
        $defaults = [
            'locale' => 'en',
            'locales' => ['en'],
            'prefix_locale' => true,

            'set_time_limit' => 30,
            'date_default_timezone_set' => 'Europe/Bratislava'
        ];

        $options = array_replace_recursive($defaults, $options);
        extract($options);

        $this->locale = $locale;
        $this->locales = (array) $locales;
        $this->prefix_locale = !!$prefix_locale;

        if (file_exists("site/i18l/{$this->locale}.php")) {
            static::$i18l = require "site/i18l/{$this->locale}.php";
        }

        set_time_limit((int) $set_time_limit);
        date_default_timezone_set($date_default_timezone_set);
    }

    public function serve(array $options = [])
    {
        $defaults = [
            'uri' => null,
            'contentFilter' => null,
            'useCache' => true,
            'cacheOptions' => [
            ]
        ];

        $options = array_replace_recursive($defaults, $options);
        extract($options);

        if ($uri !== null && !is_string($uri)) {
            throw new \Exception("`url` must be a string or null", 400);
        }

        if ($uri === null || strlen(trim($uri)) === 0) {
            // Clean REQUEST_URI
            $uri = $_SERVER['REQUEST_URI'];
        }

        if ($contentFilter !== null && !is_callable($contentFilter)) {
            throw new \Exception("`contentFilter` must be callable", 400);
        }

        if (!is_array($cacheOptions)) {
            throw new \Exception("`cacheOptions` must an array", 400);
        }

        $useCache = !!$useCache;

        $uri = explode('?', $uri);
        $uri = explode('/', trim($uri[0], '/'));

        if ($useCache) {
            // Serve from cache

            $cacheOptions = array_replace_recursive([
                'path' => 'cache/html',
                'requestUri' => empty($_GET) ? '/'.implode('/', $uri) : '/'.implode('/', $uri).'?'.implode('&', $_GET),
                'ttl' => 5 * 60 /*5 minutes*/
            ], $cacheOptions);

            $cache = new \RFPL\Cache($cacheOptions);
            try { $cache->serve($contentFilter); } catch (\Exception $e) {}
        }

        // Locale is prefixed in the URI
        if ($this->prefix_locale) {
            if (in_array($uri[0], $this->locales)) {
                $locale = $uri[0];
            }

            if (count($uri) === 2) {
                $page = $uri[1];
            }

            if (!in_array($locale, $this->locales)) {
                if (!@$page) {
                    $newLocation = static::getProtocol().static::getHttpHost().'/en/'.$uri[0];
                } else {
                    $newLocation = static::getProtocol().static::getHttpHost().'/en';
                }

                header('HTTP/1.1 302 Temporarily Moved');
                header('Location: '.$newLocation);

                exit('You are now being now redirected to new location: '.$newLocation);
            }
        } else {
            $locale = $this->locale;
            $page = implode('/', $uri);
        }

        if (!@$page) {
            $page = 'index';
        }

        // header('Content-Type: text/html; charset=utf-8');

        $layout = new \UIML\Document("site/pages/{$page}.php", 'site/tags', '.php');

        $layout->registerTagFilter(function($html) {
            return static::translateXML($html);
        });

        // Convert to string
        if (strtolower($_SERVER['REQUEST_METHOD']) !== 'get') {
            return $contentFilter("<!doctype html>\n".$layout);
        } else {
            return "<!doctype html>\n".$layout;
        }
    }

    /**
     * Translates string with <lang></lang> marks
     *
     * @param $html ttring Input string
     * @return string Translated string
     *
     */
    public function translateXML($html)
    {
        foreach ($this->locales as $l) {
            if ($l === $this->locale) {
                $html = preg_replace('@\s*<'.$l.'>(.*)</'.$l.'>\s*@sU', '$1', $html);
            } else {
                $html = preg_replace('@\s*<'.$l.'>.*</'.$l.'>\s*@sU', '', $html);
            }
        }

        return $html;
    }

    /**
     * Simple XOR string encoding
     *
     * @param string $str Input string
     * @param string $salt String encoding salt
     *
     */
    public static function xorString($str, $salt = null)
    {
        if (empty($salt)) {
            return '$salt is missing';
        }

        $result = '';

        for ($i = 0; $i < strlen($str); $i++) {
            $tmp = $str[$i];

            for ($j = 0; $j < strlen($salt); $j++) {
                $tmp = chr(ord($tmp) ^ ord($salt[$j]));
            }

            $result .= $tmp;
        }

        return base64_encode($result);
    }

    /**
     * Retrieves translation
     *
     * @param string $string String to translate
     * @return string
     *
     */
    public static function translate($string = '')
    {
        if (isset(static::$i18l[$string])) {
            return static::$i18l[$string];
        }

        return $string;
    }

    /**
     * Returns current protocol
     *
     * @return string Returns 'http://' or `https://`
     *
     */
    public static function getProtocol()
    {
        $isSecure = false;

        if (isset($_SERVER['HTTPS']) && !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return 'https://';
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https' || !empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] == 'on') {
            return 'https://';
        }

        return 'http://';
    }

    /**
     * Returns  current HTTP Host
     *
     * @return string Host name
     *
     */
    public static function getHttpHost()
    {
        return $_SERVER['HTTP_HOST'];
    }

    /**
     * Checks whether request was made using Ajax
     *
     * @return boolean Returns `true` if request was made using Ajax
     *
     */
    public static function isAjax()
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && trim(strtolower($_SERVER['HTTP_X_REQUESTED_WITH'])) === 'xmlhttprequest';
    }

    /**
     * Returns Base64 encoded file content
     *
     * @param string $source Path to file
     * @return string Base64 encoded string
     *
     */
    public static function base64EncodeFile($source)
    {
        if (!file_exists($source)) {
            // Try relative
            $source = ltrim($source, '/');
        }

        if (!file_exists($source)) {
            throw new Exception('base64EncodeImage:404');
        }

        return 'data:'.mime_content_type($source).';base64,'.base64_encode(file_get_contents($source));
    }

    public static function getCSSLinkTag($file, $publicRoot = null, $oldIe = false)
    {
        if (!preg_match('#\.css$#', $file)) {
            throw new \Exception("File must be a `.css` file", 400);
        }

        if (strstr($file, '.min.css')) {
            $minifiedFile = $file;
            $file = str_replace('.min.css', '.css', $file);
        } else {
            $minifiedFile = str_replace('.css', '.min.css', $file);
        }

        $file = realpath($file);
        $minifiedFile = realpath($minifiedFile);

        if (!$file) {
            $file = $minifiedFile;
        }

        if (!$minifiedFile) {
            $minifiedFile = $file;
        }

        if (!$file || !$minifiedFile) {
            throw new \Exception("CSS file does not exist", 404);
        }

        $filemtime = filemtime($file);
        $minifiedFilemtime = filemtime($minifiedFile);

        $linkCSSFile = $minifiedFilemtime >= $filemtime ? $minifiedFile : $file;

        if ($publicRoot === null) {
            $publicRoot = dirname($linkCSSFile);
        } else {
            $publicRoot = realpath($publicRoot);

            if (!$publicRoot) {
                throw new \Exception("Public root path does not exist", 404);
            }

            if (!preg_match('#^'.$publicRoot.'/#', $linkCSSFile)) {
                throw new \Exception("Public root path and CSS file path do not match", 400);
            }
        }

        $link = '<link rel="stylesheet" href="'.preg_replace('#\.css$#', '.', str_replace($publicRoot, '', $linkCSSFile)).filemtime($linkCSSFile).'.css">';

        if ($oldIe) {
            try {
                $oldIeFile = static::putOldIeCSS($file);
                $minifiedOldIeFile = realpath(str_replace('.css', '.min.css', $oldIeFile));

                $link.= "\n".'<!--[if lt IE 8]>';

                if ($minifiedOldIeFile && filemtime($minifiedOldIeFile) >= filemtime($oldIeFile)) {
                    $link.= '<link rel="stylesheet" href="'.preg_replace('#\.css$#', '.', str_replace($publicRoot, '', $minifiedOldIeFile)).filemtime($minifiedOldIeFile).'.css">';
                } else {
                    $link.= '<link rel="stylesheet" href="'.preg_replace('#\.css$#', '.', str_replace($publicRoot, '', $oldIeFile)).filemtime($oldIeFile).'.css">';
                }

                $link.= '<![endif]-->';
            } catch (\Exception $e) {
                $link.="\n".$e->getMessage();
            }
        }

        return $link;
    }

    static public function putOldIeCSS($file)
    {
        if (!preg_match('#\.css$#', $file)) {
            throw new \Exception("File must be a `.css` file", 400);
        }

        $file = realpath($file);
        $oldIeFile = preg_replace('#\.css#', '.oldie.css', $file);

        // Already newer
        if (filemtime($oldIeFile) >= filemtime($file)) {
            return $oldIeFile;
        }

        if (!$file) {
            throw new \Exception("CSS file does not exist", 404);
        }

        $css = preg_replace('#(@[a-zA-Z-]+.*?\{.*?\}\s*\})#ms', '[[split]]$1[[split]]', str_replace("\n", '', file_get_contents($file)));
        $css = array_filter(explode('[[split]]', $css));
        $newCss = [];

        foreach ($css as &$_css) {
            $_css = array_filter(preg_split('#[\{\}]#', $_css));

            if (strstr($_css[0], '@')) {
                if (!strstr($_css[0], '@media') || strstr($_css[0], 'max-') || strstr($_css[0], 'portrait')) {
                    // Discard rules
                    $_css = [];
                } else {
                    array_shift($_css);
                }
            }

            for ($i = 0; $i < count($_css); $i += 2) {
                foreach (array_filter(array_map('trim', explode(',', $_css[$i]))) as $rule) {
                    if (!isset($newCss[$rule])) {
                        $newCss[$rule] = [];
                    }

                    if ($_css[($i+1)]) {
                        foreach (static::explodeProperties($_css[($i+1)]) as $property => $style) {
                            $newCss[$rule][$property] = $style;
                        }
                    }
                };
            }
        }

        $hashes = [];

        foreach ($newCss as $rule => &$styles) {
            ksort($styles);
            $styles = static::implodeProperties($styles);
            $hash = hash('sha256', $styles);

            if (!isset($hashes[$hash])) {
                $hashes[$hash] = [];
            }

            $hashes[$hash][] = $rule;
        }

        $newCombinedCss = [];

        foreach ($hashes as $rules) {
            $newCombinedCss [] = implode(',', $rules). '{'.$newCss[$rules[0]].'}';
        }

        file_put_contents($oldIeFile, implode("\n", $newCombinedCss));

        return $oldIeFile;
    }

    static protected function explodeProperties($properties)
    {
        if (!is_string($properties)) {
            throw new \Exception("Argument 1 passed to ".__CLASS__."::".__METHOD__."() must be of the type string", 500);
        }

        $assocRules = [];

        foreach (array_filter(explode(';', $properties)) as $row)
        {
            if (strstr($row, ':')) {
                $row = explode(':', $row, 2);
                $assocRules[$row[0]] = $row[1];
            }
        }

        return $assocRules;
    }

    static protected function implodeProperties(array $properties)
    {
        $_properties = [];

        foreach ($properties as $key => $value) {
            $_properties[] = trim($key).':'.trim($value);
        }

        return implode(';', $_properties);
    }
}
