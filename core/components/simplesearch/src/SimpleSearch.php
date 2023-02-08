<?php

namespace SimpleSearch;

use MODX\Revolution\modX;
use MODX\Revolution\modChunk;
use MODX\Revolution\Services\ContainerException;
use SimpleSearch\Driver\SimpleSearchDriver;
use SimpleSearch\Driver\SimpleSearchDriverBasic;

class SimpleSearch
{
    public modX $modx;
    public array $config = [];
    public string $searchString = '';
    public array $searchArray = [];
    public int $searchResultsCount = 0;
    public string $ids = '';
    public array $docs = [];
    public array $chunks = [];
    public SimpleSearchDriver $driver;
    public SiHooks $postHooks;
    public array $response = [];

    public function __construct(modX $modx, array $config = [])
    {
        $this->modx = $modx;
        $corePath   = $this->modx->getOption('simplesearch.core_path', null, $this->modx->getOption('core_path') . 'components/simplesearch/');
        $assetsUrl  = $this->modx->getOption('simplesearch.assets_url', null, $this->modx->getOption('assets_url') . 'components/simplesearch/');

        $this->config = array_merge([
            'corePath'        => $corePath,
            'chunksPath'      => $corePath.'elements/chunks/',
            'snippetsPath'    => $corePath.'elements/snippets/',
            'srcPath'         => $corePath . 'src/',
            'processors_path' => $corePath . '/src/Processors/',
            'assetsUrl'       => $assetsUrl,
        ], $config);

        $this->modx->lexicon->load('simplesearch:default');
    }

    /**
     * Gets a Chunk and caches it; also falls back to file-based templates
     * for easier debugging.
     *
     * @access public
     * @param string $name The name of the Chunk
     * @param array $properties The properties for the Chunk
     * @return string The processed content of the Chunk
     */
    public function getChunk(string $name, array $properties = []): string
    {
        try {
            if ($pdo = $this->modx->services->get('pdotools')) {
                return $pdo->getChunk($name, $properties);
            }
        } catch (ContainerException $e) {
            if (class_exists('pdoTools') && $pdo = $this->modx->getService('pdoTools')) {
                return $pdo->getChunk($name, $properties);
            }
        }

        if (!isset($this->chunks[$name])) {
            $chunk = $this->getTplChunk($name);
            if ($chunk === null) {
                $chunk = $this->modx->getObject(modChunk::class, ['name' => $name]);
                if ($chunk === false) {
                    return false;
                }
            }
            $this->chunks[$name] = $chunk->getContent();
        } else {
            $o = $this->chunks[$name];
            $chunk = $this->modx->newObject(modChunk::class);
            $chunk->setContent($o);
        }

        $chunk->setCacheable(false);

        return $chunk->process($properties);
    }

    /**
     * Returns a modChunk object from a template file.
     *
     * @access private
     * @param string $name The name of the Chunk. Will parse to name.chunk.tpl
     * @return null|modChunk Returns the modChunk object if found, otherwise
     * false.
     */
    private function getTplChunk(string $name): ?modChunk
    {
        $chunk = null;
        $f = file_exists($name)
            ? $name
            : $this->config['chunksPath'] . strtolower($name) . '.chunk.tpl';

        if (file_exists($f)) {
            $o = file_get_contents($f);
            $chunk = $this->modx->newObject(modChunk::class);

            $chunk->set('name', $name);
            $chunk->setContent($o);
        }

        return $chunk;
    }

    /**
     * Load the driver for SimpleSearch
     *
     * @param array $scriptProperties
     * @return false
     */
    public function loadDriver(array $scriptProperties = []): bool
    {
        $driverClass = $this->modx->getOption('simplesearch.driver_class', $scriptProperties, SimpleSearchDriverBasic::class, true);

        if (!class_exists($driverClass)) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, 'Could not load driver class: '.$driverClass);
            return false;
        }

        $this->driver = new $driverClass($this, $scriptProperties);

        return true;
    }

    /**
     * Parses search string and removes any potential security risks in the search string
     *
     * @param string $str The string to parse.
     * @return string The parsed and cleansed string.
     */
    public function parseSearchString(string $str = ''): string
    {
        $minChars = $this->modx->getOption('minChars', $this->config, 4);

        $this->searchArray = explode(' ', $str);
        $this->searchArray = $this->modx::sanitize($this->searchArray, $this->modx->sanitizePatterns);

        $reserved = ['AND', 'OR', 'IN', 'NOT'];
        foreach ($this->searchArray as $key => $term) {
            $this->searchArray[$key] = strip_tags($term);
            if (strlen($term) < $minChars && !in_array($term, $reserved, true)) {
                unset($this->searchArray[$key]);
            }
        }

        $this->searchString = implode(' ', $this->searchArray);
        /* One last pass to filter for modx tags. */
        $this->searchString = str_replace(['[[', ']]'], ['&#91;&#91;', '&#93;&#93;'], $this->searchString);

        return $this->searchString;
    }

    /**
     * Gets a modResource collection that matches the search terms
     *
     * @param string $str The string to use to search with.
     * @param array $scriptProperties
     * @return array An array of modResource results of the search.
     */
    public function getSearchResults(string $str = '', array $scriptProperties = []): array
    {
        if (!empty($str)) {
            $this->searchString = strip_tags($this->modx->sanitizeString($str));
        }

        if (!$this->loadDriver($scriptProperties)) {
            return [];
        }
        $this->response           = $this->driver->search($str, $scriptProperties);
        $this->searchResultsCount = $this->response['total'];
        $this->docs               = $this->response['results'];

        return $this->response;
    }

    /**
     * Generates the pagination links
     *
     * @param string $searchString The string of the search
     * @param int $perPage The number of items per page
     * @param string $separator The separator to use between pagination links
     * @param bool|int $total The total of records. Will default to the main count if not passed
     * @return string Pagination links.
     * @noinspection SlowArrayOperationsInLoopInspection
     */
    public function getPagination(string $searchString = '', int $perPage = 10, string $separator = ' | ', int $total = 0): string
    {
        if (empty($total)) {
            $total = $this->response['total'];
        }

        $pagination = '';

        /* Setup default properties */
        $searchIndex    = $this->modx->getOption('searchIndex', $this->config, 'search');
        $searchOffset   = $this->modx->getOption('offsetIndex', $this->config, 'simplesearch_offset');
        $pageTpl        = $this->modx->getOption('pageTpl', $this->config, 'PageLink');
        $currentPageTpl = $this->modx->getOption('currentPageTpl', $this->config, 'CurrentPageLink');
        $urlScheme      = $this->modx->getOption('urlScheme', $this->config, -1);

        /* Get search string */
        if (empty($searchString)) {
            $searchString = $this->searchString;
        } else {
            $searchString = isset($_REQUEST[$searchIndex]) ? (string)$_REQUEST[$searchIndex] : '';
        }

        $pageLinkCount = ceil($total / $perPage);
        $pageArray     = [];
        $id            = $this->modx->resource->get('id');
        $pageLimit     = $this->modx->getOption('pageLimit', $this->config, 0);
        $pageFirstTpl  = $this->modx->getOption('pageFirstTpl', $this->config, $pageTpl);
        $pageLastTpl   = $this->modx->getOption('pageLastTpl', $this->config, $pageTpl);
        $pagePrevTpl   = $this->modx->getOption('pagePrevTpl', $this->config, $pageTpl);
        $pageNextTpl   = $this->modx->getOption('pageNextTpl', $this->config, $pageTpl);

        for ($i = 0; $i < $pageLinkCount; ++$i) {
            $pageArray['separator'] = $separator;
            $pageArray['offset']    = $i * $perPage;

            $currentOffset = (int)$this->modx->getOption($searchOffset, $_GET, 0);
            if (!empty($pageFirstTpl) && $pageLimit > 0 && $i + 1 === 1 && (int)$pageArray['offset'] !== $currentOffset) {
                $parameters = $this->modx->request->getParameters();
                $parameters = array_merge(
                    $parameters,
                    [
                        $searchOffset => $pageArray['offset'],
                        $searchIndex  => $searchString
                    ]
                );

                $pageArray['text'] = 'First';
                $pageArray['link'] = $this->modx->makeUrl($id, '', $parameters, $urlScheme);

                $pagination .= $this->getChunk($pageFirstTpl, $pageArray);
                if (!empty($pagePrevTpl) && ($currentOffset - $perPage) >= $perPage) {
                    $parameters = $this->modx->request->getParameters();
                    $parameters = array_merge(
                        $parameters,
                        [
                            $searchOffset => $currentOffset - $perPage,
                            $searchIndex  => $searchString,
                        ]
                    );

                    $pageArray['text'] = '&lt;&lt;';
                    $pageArray['link'] = $this->modx->makeUrl($id, '', $parameters, $urlScheme);

                    $pagination .= $this->getChunk($pagePrevTpl, $pageArray);
                }
            }
            if (empty($pageLimit) || ((int)$pageArray['offset'] >= $currentOffset - ($pageLimit * $perPage) && (int)$pageArray['offset'] <= $currentOffset + ($pageLimit * $perPage))) {
                if ($currentOffset === $pageArray['offset']) {
                    $pageArray['text'] = $i + 1;
                    $pageArray['link'] = $i + 1;

                    $pagination .= $this->getChunk($currentPageTpl, $pageArray);
                } else {
                    $parameters = $this->modx->request->getParameters();
                    $parameters = array_merge(
                        $parameters,
                        [
                            $searchOffset => $pageArray['offset'],
                            $searchIndex  => $searchString
                        ]
                    );

                    $pageArray['text'] = $i + 1;
                    $pageArray['link'] = $this->modx->makeUrl($id, '', $parameters, $urlScheme);
                    $pagination .= $this->getChunk($pageTpl, $pageArray);
                }
            }
            if (!empty($pageLastTpl) && $pageLimit > 0 && $i + 1 === (int)$pageLinkCount && (int)$pageArray['offset'] !== $currentOffset) {
                if (!empty($pageNextTpl) && ($currentOffset + $perPage) <= $total) {
                    $parameters = $this->modx->request->getParameters();
                    $parameters = array_merge(
                        $parameters,
                        [
                            $searchOffset => $currentOffset + $perPage,
                            $searchIndex  => $searchString,
                        ]
                    );

                    $pageArray['text'] = '&gt;&gt;';
                    $pageArray['link'] = $this->modx->makeUrl($id, '', $parameters, $urlScheme);

                    $pagination .= $this->getChunk($pageNextTpl, $pageArray);
                }

                $parameters = $this->modx->request->getParameters();
                $parameters = array_merge(
                    $parameters,
                    [
                        $searchOffset => $pageArray['offset'],
                        $searchIndex  => $searchString,
                    ]
                );

                $pageArray['text'] = 'Last';
                $pageArray['link'] = $this->modx->makeUrl($id, '', $parameters, $urlScheme);

                $pagination .= $this->getChunk($pageLastTpl, $pageArray);
            }

            $pagination .= $separator;
        }

        return trim($pagination, $separator);
    }

    /**
     * Sanitize a string
     *
     * @param string $text The text to sanitize
     * @return string The sanitized text
     */
    public function sanitize(string $text): string
    {
        $text = strip_tags($text);
        $text = preg_replace('/(\[\[\+.*?]])/', '', $text);

        return $this->modx->stripTags($text) ?: '';
    }

    /**
     * Create an extract from the passed text parameter
     *
     * @param string $text The text that the extract will be created from.
     * @param int $length The length of the extract to be generated.
     * @param string $search The search term to center the extract around.
     * @param string $ellipsis The ellipsis to use to wrap around the extract.
     * @return string The generated extract.
     */
    public function createExtract(string $text, int $length = 200, string $search = '', string $ellipsis = '...'): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $this->sanitize($text)));
        if (empty($text)) {
            return '';
        }

        $useMb    = $this->modx->getOption('use_multibyte', null, false) && function_exists('mb_strlen');
        $encoding = $this->modx->getOption('modx_charset', null, 'UTF-8');

        $trimChars = "\t\r\n -_()!~?=+/*\\,.:;\"'[]{}`&";
        if (empty($search)) {
            $stringLength = $useMb ? mb_strlen($text, $encoding) : strlen($text);
            $end          = min(($length - 1), $stringLength);

            if ($useMb) {
                $pos = min(mb_strpos($text, ' ', $end, $encoding), mb_strpos($text, '.', $end, $encoding));
            } else {
                $pos = min(strpos($text, ' ', $end), strpos($text, '.', $end));
            }
            if ($pos) {
                return rtrim($useMb ? mb_substr($text, 0, $pos, $encoding) : substr($text, 0, $pos), $trimChars) . $ellipsis;
            }

            return $text;
        }

        if ($useMb) {
            $wordPos  = mb_strpos(mb_strtolower($text, $encoding), mb_strtolower($search, $encoding), null, $encoding);
            $halfSide = (int) $wordPos - $length / 2 + mb_strlen($search, $encoding) / 2;
            if ($halfSide > 0) {
                $halfText = mb_substr($text, 0, $halfSide, $encoding);
                $pos_start = max(mb_strrpos($halfText, ' ', 0, $encoding), mb_strrpos($halfText, '.', 0, $encoding));
                if (!$pos_start) {
                    $pos_start = 0;
                }
            } else {
                $pos_start = 0;
            }

            if ($wordPos && $halfSide > 0) {
                $l          = $pos_start + $length - 1;
                $realLength = mb_strlen($text, $encoding);
                if ($l > $realLength) {
                    $l = $realLength;
                }

                $pos_end = min(mb_strpos($text, ' ', $l, $encoding), mb_strpos($text, '.', $l, $encoding)) - $pos_start;
                if (!$pos_end || $pos_end <= 0) {
                    $extract = $ellipsis . ltrim(mb_substr($text, $pos_start, mb_strlen($text, $encoding), $encoding), $trimChars);
                } else {
                    $extract = $ellipsis . trim(mb_substr($text, $pos_start, $pos_end, $encoding), $trimChars) . $ellipsis;
                }
            } else {
                $l = $length - 1;
                $trueLength = mb_strlen($text, $encoding);
                if ($l > $trueLength) {
                    $l = $trueLength;
                }

                $pos_end = min(mb_strpos($text, ' ', $l, $encoding), mb_strpos($text, '.', $l, $encoding));
                if ($pos_end) {
                    $extract = rtrim(mb_substr($text, 0, $pos_end, $encoding), $trimChars) . $ellipsis;
                } else {
                    $extract = $text;
                }
            }
        } else {
            $wordPos  = stripos($text, $search);
            $halfSide = (int) $wordPos - $length / 2 + strlen($search) / 2;
            if ($halfSide > 0) {
                $halfText  = substr($text, 0, $halfSide);
                $pos_start = max(strrpos($halfText, ' '), strrpos($halfText, '.'));
                if (!$pos_start) {
                    $pos_start = 0;
                }
            } else {
                $pos_start = 0;
            }

            if ($wordPos && $halfSide > 0) {
                $l          = $pos_start + $length - 1;
                $realLength = strlen($text);
                if ($l > $realLength) {
                    $l = $realLength;
                }

                $pos_end = min(strpos($text, ' ', $l), strpos($text, '.', $l)) - $pos_start;
                if (!$pos_end || $pos_end <= 0) {
                    $extract = $ellipsis . ltrim(substr($text, $pos_start), $trimChars);
                } else {
                    $extract = $ellipsis . trim(substr($text, $pos_start, $pos_end), $trimChars) . $ellipsis;
                }
            } else {
                $pos_end = min(strpos($text, ' ', $length - 1), strpos($text, '.', $length - 1));
                if ($pos_end) {
                    $extract = rtrim(substr($text, 0, $pos_end), $trimChars) . $ellipsis;
                } else {
                    $extract = $text;
                }
            }
        }

        return $extract;
    }

    /**
     * Adds highlighting to the passed string
     *
     * @param string $string The string to be highlighted.
     * @param string $cls The CSS class to add to the tag wrapper
     * @param string $tag The type of HTML tag to wrap with
     * @return string The highlighted string
     */
    public function addHighlighting(string $string, string $cls = 'simplesearch-highlight', string $tag = 'span'): string
    {
        $searchStrings = explode(' ', $this->searchString);
        foreach ($searchStrings as $searchString) {
            $quoteValue = preg_quote($searchString, '/');
            $string     = preg_replace('/' . $quoteValue . '/i', '<'.$tag.' class="'.$cls.'">$0</'.$tag.'>', $string);
        }

        return $string;
    }

    /**
     * Either return a value or set to placeholder, depending on setting
     *
     * @param string $output
     * @param bool $toPlaceholder
     * @return string
     */
    public function output(string $output = '', bool $toPlaceholder = false): string
    {
        if (!empty($toPlaceholder)) {
            $this->modx->setPlaceholder($toPlaceholder, $output);

            return '';
        }

        return $output;
    }

    /**
     * Loads the Hooks class.
     *
     * @access public
     * @param string $type The type of hook to load.
     * @param array $config An array of configuration parameters for the
     * hooks class
     * @return null|SiHooks An instance of the SiHooks class.
     */
    public function loadHooks(string $type = 'post', array $config = []): ?SiHooks
    {
        if (!$this->modx->loadClass(SiHooks::class, $this->config['modelPath'], true, true)) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[SimpleSearch] Could not load Hooks class.');

            return null;
        }
        $type .= 'Hooks';

        $this->$type = new SiHooks($this, $config);

        return $this->$type;
    }
}
