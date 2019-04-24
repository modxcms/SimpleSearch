<?php
/**
 * SimpleSearch
 *
 * Copyright 2010-11 by Shaun McCormick <shaun+sisea@modx.com>
 *
 * This file is part of SimpleSearch, a simple search component for MODx
 * Revolution. It is loosely based off of AjaxSearch for MODx Evolution by
 * coroico/kylej, minus the ajax.
 *
 * SimpleSearch is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation; either version 2 of the License, or (at your option) any later
 * version.
 *
 * SimpleSearch is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU General Public License for more
 * details.
 *
 * You should have received a copy of the GNU General Public License along with
 * SimpleSearch; if not, write to the Free Software Foundation, Inc., 59 Temple Place,
 * Suite 330, Boston, MA 02111-1307 USA
 *
 * @package simplesearch
 */
/**
 * SimpleSearch snippet
 *
 * @var modX $modx
 * @var array $scriptProperties
 * @package simplesearch
 */
require_once $modx->getOption('sisea.core_path',null,$modx->getOption('core_path').'components/simplesearch/').'model/simplesearch/simplesearch.class.php';
$search = new SimpleSearch($modx,$scriptProperties);

/* find search index and toplaceholder setting */
$searchIndex = $modx->getOption('searchIndex',$scriptProperties,'search');
$toPlaceholder = $modx->getOption('toPlaceholder',$scriptProperties,false);
$noResultsTpl = $modx->getOption('noResultsTpl',$scriptProperties,'SearchNoResults');
$debug = (bool)$modx->getOption('debug', $scriptProperties, false);

$debug_output = '';
/* get search string */
if (empty($_REQUEST[$searchIndex])) {
    $output = $search->getChunk($noResultsTpl,array(
        'query' => '',
    ));

    if ( $debug ) {
        $debug_output .= '<br>No search in the URL request for searchIndex: '.$searchIndex;
    }
    return $debug_output.$search->output($output,$toPlaceholder);
}
$searchString = $search->parseSearchString($_REQUEST[$searchIndex]);
if (!$searchString) {
    $output = $search->getChunk($noResultsTpl,array(
        'query' => $searchString,
    ));

    if ( $debug ) {
        $debug_output .= '<br>Search string was empty after parsing &amp; sanitizing for searchIndex: '.$searchIndex;
    }
    return $debug_output.$search->output($output,$toPlaceholder);
}

/* setup default properties */
$tpl = $modx->getOption('tpl',$scriptProperties,'SearchResult');
$containerTpl = $modx->getOption('containerTpl',$scriptProperties,'SearchResults');
$showExtract = $modx->getOption('showExtract',$scriptProperties,true);
$extractSource = $modx->getOption('extractSource',$scriptProperties,'content');
$extractLength = $modx->getOption('extractLength',$scriptProperties,200);
$extractEllipsis = $modx->getOption('extractEllipsis',$scriptProperties,'...');
$highlightResults = $modx->getOption('highlightResults',$scriptProperties,true);
$highlightClass = $modx->getOption('highlightClass',$scriptProperties,'sisea-highlight');
$highlightTag = $modx->getOption('highlightTag',$scriptProperties,'span');
$perPage = $modx->getOption('perPage',$scriptProperties,10);
$pagingSeparator = $modx->getOption('pagingSeparator',$scriptProperties,' | ');
$placeholderPrefix = $modx->getOption('placeholderPrefix',$scriptProperties,'sisea.');
$includeTVs = $modx->getOption('includeTVs',$scriptProperties,'');
$processTVs = $modx->getOption('processTVs',$scriptProperties,'');
$tvPrefix = $modx->getOption('tvPrefix',$scriptProperties,'');
$offsetIndex = $modx->getOption('offsetIndex',$scriptProperties,'sisea_offset');
$idx = isset($_REQUEST[$offsetIndex]) ? intval($_REQUEST[$offsetIndex]) + 1 : 1;
$postHooks = $modx->getOption('postHooks',$scriptProperties,'');
$activeFacet = $modx->getOption('facet',$_REQUEST,$modx->getOption('activeFacet',$scriptProperties,'default'));
$activeFacet = $modx->sanitizeString($activeFacet);
$onlyFacet = $modx->getOption('onlyFacet', $scriptProperties, null);
$facetLimit = $modx->getOption('facetLimit',$scriptProperties,5);
$outputSeparator = $modx->getOption('outputSeparator',$scriptProperties,"\n");
$addSearchToLink = intval($modx->getOption('addSearchToLink',$scriptProperties,"0"));
$searchInLinkName = $modx->getOption('searchInLinkName',$scriptProperties,"search");

/* get results */
if ( !is_null($onlyFacet) && $onlyFacet != 'default' ) {
    $activeFacet = $onlyFacet;
} else {
    $response = $search->getSearchResults($searchString, $scriptProperties);
    $placeholders = array('query' => $searchString);
    $resultsTpl = array('default' => array('results' => array(), 'total' => $response['total']));
    if (!empty($response['results'])) {
        if ($debug) {
            $debug_output .= '<br>Begin iterate through search results';
        }
        /* iterate through search results */
        foreach ($response['results'] as $resourceArray) {
            $resourceArray['idx'] = $idx;
            if ($debug) {
                $debug_output .= '<br>Search found resource ID: ' . $resourceArray['id'];
            }
            if (empty($resourceArray['link'])) {
                $ctx = !empty($resourceArray['context_key']) ? $resourceArray['context_key'] : $modx->context->get('key');
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
                $extract = $search->createExtract($text, $extractLength, $extract, $extractEllipsis);
                /* cleanup extract */
                $extract = strip_tags(preg_replace("#\<!--(.*?)--\>#si", '', $extract));
                $extract = preg_replace("#\[\[(.*?)\]\]#si", '', $extract);
                $extract = str_replace(array('[[', ']]'), '', $extract);
                $resourceArray['extract'] = !empty($highlightResults) ? $search->addHighlighting($extract, $highlightClass, $highlightTag) : $extract;
            }
            $resultsTpl['default']['results'][] = $search->getChunk($tpl, $resourceArray);
            $idx++;
        }
    } else {
        if ($debug) {
            $debug_output .= '<br>No search results for search term';
        }
    }
}
/* load postHooks to get faceted results */
$isFacetResults = false;
if (!empty($postHooks)) {
    if ($debug) {
        $debug_output .= '<br>Post hooks found';
    }
    $limit = !empty($facetLimit) ? $facetLimit : $perPage;
    $search->loadHooks('post');
    $search->postHooks->loadMultiple($postHooks,$response['results'],array(
        'hooks' => $postHooks,
        'search' => $searchString,
        'offset' => !empty($_GET[$offsetIndex]) ? intval($_GET[$offsetIndex]) : 0,
        'limit' => $limit,
        'perPage' => $limit,
    ));
    if (!empty($search->postHooks->facets)) {
        foreach ($search->postHooks->facets as $facetKey => $facetResults) {

            if ($debug) {
                $debug_output .= '<br>Facet key: '.$facetKey;
            }
            if (empty($resultsTpl[$facetKey])) {
                $resultsTpl[$facetKey] = array();
                $resultsTpl[$facetKey]['total'] = $facetResults['total'];
                $resultsTpl[$facetKey]['results'] = array();
                if ($debug) {
                    $debug_output .= ' - results have not yet been added';
                }
            } else {
                $resultsTpl[$facetKey]['total'] = $facetResults['total'];
                if ($debug) {
                    $debug_output .= ' - results have already been added: '.$resultsTpl[$facetKey]['total'];
                }
            }

            $idx = !empty($resultsTpl[$facetKey]) ? count($resultsTpl[$facetKey]['results'])+1 : 1;
            foreach ($facetResults['results'] as $r) {
                if ($debug) {
                    $debug_output .= '<br>'.$facetKey.' results # '.$idx;
                }
                $r['idx'] = $idx;
                $fTpl = !empty($scriptProperties['tpl'.$facetKey]) ? $scriptProperties['tpl'.$facetKey] : $tpl;
                $resultsTpl[$facetKey]['results'][] = $search->getChunk($fTpl,$r);
                $idx++;
            }
        }
    }
} else {
    if ($debug) {
        $debug_output .= '<br>No post hooks found';
    }
}

/* set faceted results to placeholders for easy result positioning */
$output = array();
foreach ($resultsTpl as $facetKey => $facetResults) {
    $resultSet = implode($outputSeparator,$facetResults['results']);
    $placeholders[$facetKey.'.results'] = $resultSet;
    $placeholders[$facetKey.'.total'] = !empty($facetResults['total']) ? $facetResults['total'] : 0;
    $placeholders[$facetKey.'.key'] = $facetKey;
    if ( $placeholders[$facetKey.'.total'] > 0 ) {
        $isFacetResults = true;
    }
}
if ($debug) {
    $debug_output .= '<br>Active facet: '.$activeFacet;
}
$placeholders['results'] = $placeholders[$activeFacet.'.results']; /* set active facet results */
$placeholders['total'] = !empty($resultsTpl[$activeFacet]['total']) ? $resultsTpl[$activeFacet]['total'] : 0;
$placeholders['page'] = isset($_REQUEST[$offsetIndex]) ? ceil(intval($_REQUEST[$offsetIndex]) / $perPage) + 1 : 1;
$placeholders['pageCount'] = !empty($resultsTpl[$activeFacet]['total']) ? ceil($resultsTpl[$activeFacet]['total'] / $perPage) : 1;
if ($debug) {
    $debug_output .= '<br>Active facet total: '.$placeholders['total'].' Page: '.$placeholders['page'].' Page count: '.$placeholders['page'];
}

if (!empty($response['results']) || $isFacetResults ) {
    if ($debug) {
        $debug_output .= '<br>Results found for simple search, add highlighting and pagination';
    }
    /* add results found message */
    $placeholders['resultInfo'] = $modx->lexicon('sisea.results_found',array(
        'count' => $placeholders['total'],
        'text' => !empty($highlightResults) ? $search->addHighlighting($searchString,$highlightClass,$highlightTag) : $searchString,
    ));
    /* if perPage set to >0, add paging */
    if ($perPage > 0) {
        $placeholders['paging'] = $search->getPagination($searchString,$perPage,$pagingSeparator,$placeholders['total']);
    }
} else {
    if ($debug) {
        $debug_output .= '<br> No Results found for simple search';
    }
}
$placeholders['query'] = $searchString;
$placeholders['facet'] = $activeFacet;

/* output */
$modx->setPlaceholder($placeholderPrefix.'query',$searchString);
$modx->setPlaceholder($placeholderPrefix.'count',$response['total']);
$modx->setPlaceholders($placeholders,$placeholderPrefix);

if (empty($response['results']) && !$isFacetResults ) {
    $output = $search->getChunk($noResultsTpl,array(
        'query' => $searchString,
    ));
    if ($debug) {
        $debug_output .= '<br>No results send to: '.$noResultsTpl.' Chunk';
    }
} else {
    if ($debug) {
        $debug_output .= '<br>Results found send to: '.$containerTpl.' Chunk';
    }
    $output = $search->getChunk($containerTpl,$placeholders);
}
return $debug_output.$search->output($output,$toPlaceholder);