<?php
use SimpleSearch\SimpleSearch;
/**
 * Show the search form
 *
 * @var modX $modx
 * @var array $scriptProperties
 * @package simplesearch
 */
$search = new SimpleSearch($modx, $scriptProperties);

/* Setup default options. */
$scriptProperties = array_merge(
    array(
        'tpl'           => 'SearchForm',
        'method'        => 'get',
        'searchIndex'   => 'search',
        'toPlaceholder' => '',
        'landing'       => $modx->resource->get('id'),
), $scriptProperties);

if (empty($scriptProperties['landing'])) {
  $scriptProperties['landing'] = $modx->resource->get('id');
}

/* If get value already exists, set it as default. */
$searchValue  = $_REQUEST[$scriptProperties['searchIndex']] ?? '';
$searchValues = explode(' ', $searchValue);

array_map(array($modx, 'sanitizeString'), $searchValues);

$searchValue  = implode(' ', $searchValues);
$placeholders = array(
    'method'      => $scriptProperties['method'],
    'landing'     => $scriptProperties['landing'],
    'searchValue' => strip_tags(htmlspecialchars($searchValue, ENT_QUOTES, 'UTF-8')),
    'searchIndex' => $scriptProperties['searchIndex'],
);

$output = $search->getChunk($scriptProperties['tpl'], $placeholders);

return $search->output($output, $scriptProperties['toPlaceholder']);
