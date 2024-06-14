<?php
/**
 * SimpleSearch snippet
 *
 * @var modX $modx
 * @var array $scriptProperties
 * @package simplesearch
 */
use SimpleSearch\SimpleSearch;

$search = new SimpleSearch($modx, $scriptProperties);

/* Find search index and toplaceholder setting */
$searchIndex   = $modx->getOption('searchIndex', $scriptProperties, 'search');
$toPlaceholder = $modx->getOption('toPlaceholder', $scriptProperties, '');
$noResultsTpl  = $modx->getOption('noResultsTpl', $scriptProperties, 'SearchNoResults');

/* Get search string */
if (empty($_REQUEST[$searchIndex])) {
    $output = $search->getChunk($noResultsTpl, array(
        'query' => '',
    ));

    return $search->output($output, $toPlaceholder);
}
$searchString = $search->parseSearchString($_REQUEST[$searchIndex]);
if (!$searchString) {
    $output = $search->getChunk($noResultsTpl, array(
        'query' => $searchString,
    ));

    return $search->output($output, $toPlaceholder);
}

/* Setup default properties. */
$tpl               = $modx->getOption('tpl', $scriptProperties, 'SearchResult');
$containerTpl      = $modx->getOption('containerTpl', $scriptProperties, 'SearchResults');
$showExtract       = $modx->getOption('showExtract', $scriptProperties, true);
$extractSource     = $modx->getOption('extractSource', $scriptProperties, 'content');
$extractLength     = $modx->getOption('extractLength', $scriptProperties, 200);
$extractEllipsis   = $modx->getOption('extractEllipsis', $scriptProperties, '...');
$highlightResults  = $modx->getOption('highlightResults', $scriptProperties, true);
$highlightClass    = $modx->getOption('highlightClass', $scriptProperties, 'simplesearch-highlight');
$highlightTag      = $modx->getOption('highlightTag', $scriptProperties, 'span');
$perPage           = $modx->getOption('perPage', $scriptProperties, 10);
$pagingSeparator   = $modx->getOption('pagingSeparator', $scriptProperties, ' | ');
$placeholderPrefix = $modx->getOption('placeholderPrefix', $scriptProperties, 'simplesearch.');
$includeTVs        = $modx->getOption('includeTVs', $scriptProperties, '');
$processTVs        = $modx->getOption('processTVs', $scriptProperties, '');
$tvPrefix          = $modx->getOption('tvPrefix', $scriptProperties, '');
$offsetIndex       = $modx->getOption('offsetIndex', $scriptProperties, 'simplesearch_offset');
$idx               = isset($_REQUEST[$offsetIndex]) ? (int) $_REQUEST[$offsetIndex] + 1 : 1;
$postHooks         = $modx->getOption('postHooks', $scriptProperties, '');
$activeFacet       = $modx->getOption('facet', $_REQUEST, $modx->getOption('activeFacet', $scriptProperties, 'default'));
$activeFacet       = $modx->sanitizeString($activeFacet);
$facetLimit        = $modx->getOption('facetLimit', $scriptProperties, 5);
$outputSeparator   = $modx->getOption('outputSeparator', $scriptProperties, "\n");
$addSearchToLink   = (int) $modx->getOption('addSearchToLink', $scriptProperties, 0);
$searchInLinkName  = $modx->getOption('searchInLinkName', $scriptProperties, 'search');
$noResults = true;

/* Get results */
$response     = $search->getSearchResults($searchString, $scriptProperties);
$placeholders = array('query' => $searchString);
$resultsTpl   = array('default' => array('results' => array(), 'total' => $response['total']));
if (!empty($response['results'])) {
    /* iterate through search results */
    foreach ($response['results'] as $resourceArray) {
        $resourceArray['idx'] = $idx;
        if (empty($resourceArray['link'])) {
            $ctx  = !empty($resourceArray['context_key']) ? $resourceArray['context_key'] : $modx->context->get('key');
            $args = '';
            if ($addSearchToLink) {
                $args = array($searchInLinkName => $searchString);
            }

            $resourceArray['link'] = $modx->makeUrl($resourceArray['id'], $ctx, $args);
        }

        if ($showExtract) {
            $extract = $searchString;
            if (array_key_exists($extractSource, $resourceArray)) {
                $text = $resourceArray[$extractSource];
            } else {
                $text = $modx->runSnippet($extractSource, $resourceArray);
            }

            if (isset($text) && is_string($text)) {
                $extract = $search->createExtract($text, $extractLength, $extract,$extractEllipsis);
    
                /* Cleanup extract */
                $extract = strip_tags(preg_replace("#\<!--(.*?)--\>#si", '', $extract));
                $extract = preg_replace("#\[\[(.*?)\]\]#si", '', $extract);
                $extract = str_replace(array('[[',']]'), '', $extract);
                $resourceArray['extract'] = !empty($highlightResults) ? $search->addHighlighting($extract, $highlightClass, $highlightTag) : $extract;                
            }
        }

        $resultsTpl['default']['results'][] = $search->getChunk($tpl, $resourceArray);

        $idx++;
    }
}

/* Load postHooks to get faceted results. */
if (!empty($postHooks)) {
    $limit = !empty($facetLimit) ? $facetLimit : $perPage;

    $search->loadHooks('post');
    $search->postHooks->loadMultiple($postHooks, $response['results'],
                                     array(
                                         'hooks'   => $postHooks,
                                         'search'  => $searchString,
                                         'offset'  => !empty($_GET[$offsetIndex]) ? (int) $_GET[$offsetIndex] : 0,
                                         'limit'   => $limit,
                                         'perPage' => $limit,
                                     )
    );

    if (!empty($search->postHooks->facets)) {
        foreach ($search->postHooks->facets as $facetKey => $facetResults) {
            if (empty($resultsTpl[$facetKey])) {
                $resultsTpl[$facetKey]            = array();
                $resultsTpl[$facetKey]['total']   = $facetResults['total'];
                $resultsTpl[$facetKey]['results'] = array();
            } else {
                $resultsTpl[$facetKey]['total'] = $resultsTpl[$facetKey]['total'] = $facetResults['total'];
            }

            $idx = !empty($resultsTpl[$facetKey]) ? count($resultsTpl[$facetKey]['results']) + 1 : 1;
            foreach ($facetResults['results'] as $r) {
                $r['idx']                           = $idx;
                $fTpl                               = !empty($scriptProperties['tpl' . $facetKey]) ? $scriptProperties['tpl' . $facetKey] : $tpl;
                $resultsTpl[$facetKey]['results'][] = $search->getChunk($fTpl, $r);
                $idx++;
            }
        }
    }
}

/* Set faceted results to placeholders for easy result positioning. */
$output = array();
foreach ($resultsTpl as $facetKey => $facetResults) {
    $resultSet                          = implode($outputSeparator, $facetResults['results']);
    $placeholders[$facetKey.'.results'] = $resultSet;
    $placeholders[$facetKey.'.total']   = !empty($facetResults['total']) ? $facetResults['total'] : 0;
    $placeholders[$facetKey.'.key']     = $facetKey;

    if ($placeholders[$facetKey.'.total'] !== 0) {
        $noResults = false;
    }
}

$placeholders['results']   = $placeholders[$activeFacet . '.results']; /* Set active facet results. */
$placeholders['total']     = !empty($resultsTpl[$activeFacet]['total']) ? $resultsTpl[$activeFacet]['total'] : 0;
$placeholders['page']      = isset($_REQUEST[$offsetIndex]) ? ceil((int) $_REQUEST[$offsetIndex] / $perPage) + 1 : 1;
$placeholders['pageCount'] = !empty($resultsTpl[$activeFacet]['total']) ? ceil($resultsTpl[$activeFacet]['total'] / $perPage) : 1;

if (!empty($placeholders['results'])) {
    /* add results found message */
    $placeholders['resultInfo'] = $modx->lexicon('simplesearch.results_found', array(
        'count' => $placeholders['total'],
        'text'  => !empty($highlightResults) ? $search->addHighlighting($searchString, $highlightClass, $highlightTag) : $searchString,
    ));

    /* If perPage set to >0, add paging */
    if ($perPage > 0) {
        $placeholders['paging'] = $search->getPagination($searchString, $perPage, $pagingSeparator, $placeholders['total']);
    }
}

$placeholders['query'] = $searchString;
$placeholders['facet'] = $activeFacet;

/* output */
$modx->setPlaceholder($placeholderPrefix . 'query', $searchString);
$modx->setPlaceholder($placeholderPrefix . 'count', $response['total']);
$modx->setPlaceholders($placeholders, $placeholderPrefix);

if ($noResults) {
    $output = $search->getChunk($noResultsTpl, $placeholders);
} else {
    $output = $search->getChunk($containerTpl, $placeholders);
}

return $search->output($output, $toPlaceholder);
