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
require_once dirname(__FILE__) . '/simplesearchdriver.class.php';
/**
 * ElasticSearch search driver for SimpleSearch.
 * @package simplesearch
 */
class SimpleSearchDriverElastic extends SimpleSearchDriver {
    /** @var array An array of connection properties for our SolrClient */
    private $_connectionOptions = array();

    /** @var \Elastica\Client $var */
    public $client;
    /** @var \Elastica\Index $index */
    public $index;

    /**
     * Initialize the ElasticSearch client, and setup settings for the client.
     * 
     * @return void
     */
    public function initialize() {

        spl_autoload_register(array($this, 'autoLoad'));

        $this->_connectionOptions = array(
            'url' => $this->modx->getOption('sisea.elastic.hostname', null, 'http://127.0.0.1') . ':' . $this->modx->getOption('sisea.elastic.port', null, 9200).'/',
        );

        try {
            $this->client = new \Elastica\Client($this->_connectionOptions);
            $this->index = $this->client->getIndex(strtolower($this->modx->getOption('sisea.elastic.index', null, 'siplesearchindex')));
            if(!$this->index->exists()){
                $indexSetup = $this->modx->getObject('modSnippet', array('name' => 'SimpleSearchElasticIndexSetup'));
                if ($indexSetup) {
                    $indexOptions = $this->modx->fromJSON($this->modx->runSnippet('SimpleSearchElasticIndexSetup'));
                } else {
                    $indexOptions = $this->modx->fromJSON($this->modx->runSnippet('SimpleSearchElasticIndexSetup_default'));
                }

                $this->index->create($indexOptions,true);
            }
        } catch (Exception $e) {
            $this->modx->log(xPDO::LOG_LEVEL_ERROR,'Error connecting to ElasticSearch server: '.$e->getMessage());
        }
    }

    public function autoLoad($class) {
        $file = $this->modx->getOption('sisea.core_path', null, $this->modx->getOption('core_path').'components/simplesearch/');
        $file .= 'model/simplesearch/driver/libs/' . $class . '.php';

        $file = str_replace('\\', '/', $file);

        if (file_exists($file)) {
            require_once($file);
        }
    }

    /**
     * Run the search against a sanitized query string via ElasticSearch.
     *
     * @param string $string
     * @param array $scriptProperties The scriptProperties array from the SimpleSearch snippet
     * @return array
     */
    public function search($string,array $scriptProperties = array()) {
        $fields = $this->modx->getOption('sisea.elastic.search_fields', null, 'pagetitle^20,introtext^10,alias^5,content^1');

        $fields = explode(',', $fields);
        $fields = array_map('trim', $fields);
        $fields = array_keys(array_flip($fields));
        $fields = array_filter($fields);

        if (empty($fields)) {
            return false;
        }

        /** @var \Elastica\Query\MultiMatch $query */
        $query = new \Elastica\Query\MultiMatch();
        $query->setFields($fields);
        $query->setQuery($string);

        $queryType = $this->modx->getOption('queryType', $scriptProperties, 'and');
        switch($queryType) {
            case 'phrase':
                $query->setType('phrase');
            break;
            case 'and':
            case 'or' :
                $query->setOperator($queryType);
            break;
            default :
                $query->setOperator('and');
            break;
        }

        $customFilterScore = new \Elastica\Query\FunctionScore();
        $customFilterScore->setQuery($query);

        $searchBoosts = $this->modx->getOption('sisea.elastic.search_boost', null, '');
        $searchBoosts = explode('|', $searchBoosts);
        $searchBoosts = array_map('trim', $searchBoosts);
        $searchBoosts = array_keys(array_flip($searchBoosts));
        $searchBoosts = array_filter($searchBoosts);

        $boosts = array();

        foreach ($searchBoosts as $boost) {
            $arr = array('field' => '', 'value' => '', 'boost' => 1.0);
            $field = explode('=', $boost);
            $field = array_map('trim', $field);
            $field = array_keys(array_flip($field));
            $field = array_filter($field);

            if (count($field) != 2) continue;

            $value = explode('^', $field[1]);
            $value = array_map('trim', $value);
            $value = array_keys(array_flip($value));
            $value = array_filter($value);

            if (count($value) != 2) continue;

            $arr['field'] = $field[0];
            $arr['value'] = $value[0];
            $arr['boost'] = $value[1];

            $boosts[] = $arr;
        }

        if (empty($boosts)) {
            $customFilterScore->addWeightFunction(1, new \Elastica\Filter\Term(array('type' => 'document')));
        } else {
            foreach ($boosts as $boost) {
                $customFilterScore->addWeightFunction($boost['boost'], new \Elastica\Filter\Term(array($boost['field'] => $boost['value'])));
            }
        }


        /** @var \Elastica\Query $elasticaQuery */
        $elasticaQuery = new \Elastica\Query();
        $elasticaQuery->setQuery($customFilterScore);

    	/* set limit */
        $perPage = $this->modx->getOption('perPage',$scriptProperties,10);
    	if (!empty($perPage)) {
            $offset = $this->modx->getOption('start',$scriptProperties,0);
            $offsetIndex = $this->modx->getOption('offsetIndex',$scriptProperties,'sisea_offset');
            if (isset($_REQUEST[$offsetIndex])) $offset = (int)$_REQUEST[$offsetIndex];
            $elasticaQuery->setFrom($offset);
            $elasticaQuery->setSize($perPage);
    	}

        $elasticaFilterAnd = new \Elastica\Query\BoolQuery();

        /* handle hidemenu option */
        $hideMenu = $this->modx->getOption('hideMenu',$scriptProperties,2);
        if ($hideMenu != 2) {
            $elasticaFilterHideMenu  = new \Elastica\Query\Term();
            $elasticaFilterHideMenu->setTerm('hidemenu', ($hideMenu ? 1 : 0));
            $elasticaFilterAnd->addMust($elasticaFilterHideMenu);
        }

        /* handle contexts */
        $contexts = $this->modx->getOption('contexts',$scriptProperties,'');
        $contexts = !empty($contexts) ? $contexts : $this->modx->context->get('key');
        $contexts = explode(',',$contexts);
        $elasticaFilterContext  = new \Elastica\Query\Terms();
        $elasticaFilterContext->setTerms('context_key', $contexts);
        $elasticaFilterAnd->addMust($elasticaFilterContext);

        /* handle restrict search to these IDs */
        $ids = $this->modx->getOption('ids',$scriptProperties,'');
    	  if (!empty($ids)) {
            $idType = $this->modx->getOption('idType',$this->config,'parents');
            $depth = $this->modx->getOption('depth',$this->config,10);
            $ids = $this->processIds($ids,$idType,$depth);

            $elasticaFilterId  = new \Elastica\Query\Terms();
            $elasticaFilterId->setTerms('id', $ids);
            $elasticaFilterAnd->addMust($elasticaFilterId);
        }

        /* handle exclude IDs from search */
        $exclude = $this->modx->getOption('exclude',$scriptProperties,'');
        if (!empty($exclude)) {
            $idType = $this->modx->getOption('idType',$this->config,'parents');
            $depth = $this->modx->getOption('depth',$this->config,10);
            $exclude = $this->processIds($exclude,$idType,$depth);

            $elasticaFilterExcludeId  = new \Elastica\Query\Terms();
            $elasticaFilterExcludeId->setTerms('id', $exclude);
            $elasticaFilterAnd->addMustNot($elasticaFilterExcludeId);
        }

        /* basic always-on conditions */
        $elasticaFilterPublished  = new \Elastica\Query\Term();
        $elasticaFilterPublished->setTerm('published', 1);
        $elasticaFilterAnd->addMust($elasticaFilterPublished);

        $elasticaFilterSearchable  = new \Elastica\Query\Term();
        $elasticaFilterSearchable->setTerm('searchable', 1);
        $elasticaFilterAnd->addMust($elasticaFilterSearchable);

        $elasticaFilterDeleted  = new \Elastica\Query\Term();
        $elasticaFilterDeleted->setTerm('deleted', 0);
        $elasticaFilterAnd->addMust($elasticaFilterDeleted);

        $elasticaQuery->setPostFilter($elasticaFilterAnd);

        /* sorting */
        if (!empty($scriptProperties['sortBy'])) {
            $sortDir = $this->modx->getOption('sortDir',$scriptProperties,'desc');
            $sortDirs = explode(',',$sortDir);
            $sortBys = explode(',',$scriptProperties['sortBy']);
            $dir = 'desc';
            $sortArray = array();
            for ($i=0;$i<count($sortBys);$i++) {
                if (isset($sortDirs[$i])) {
                    $dir= $sortDirs[$i];
                }
                $sortArray[] = array($sortBys[$i] => $dir);

            }
            $elasticaQuery->setSort($sortArray);
        }

        /* prepare response array */
        $response = array(
            'total' => 0,
            'start' => !empty($offset) ? $offset : 0,
            'limit' => $perPage,
            'status' => 0,
            'query_time' => 0,
            'results' => array(),
        );
        $elasticaResultSet = $this->index->search($elasticaQuery);

        $elasticaResults  = $elasticaResultSet->getResults();
        $totalResults         = $elasticaResultSet->getTotalHits();

        if ($totalResults > 0) {
            $response['total'] = $totalResults;
            $response['query_time'] = $elasticaResultSet->getTotalTime();
            $response['status'] = 1;
            $response['results'] = array();
            foreach ($elasticaResults as $doc) {
                $d = $doc->getData();

                /** @var modResource $resource */
                $resource = $this->modx->newObject($d['class_key']);
                if ($resource->checkPolicy('list')) {
                    $response['results'][] = $d;
                }
            }
        }

        return $response;
    }

    /**
     * Index a Resource.
     *
     * @param array $fields
     * @return boolean
     */
    public function index(array $fields = array()) {
        if (isset($fields['searchable']) && empty($fields['searchable'])) return false;
        if (isset($fields['published']) && empty($fields['published'])) return false;
        if (isset($fields['deleted']) && !empty($fields['deleted'])) return false;

        $type = $this->index->getType($fields['context_key']);
        $document = new \Elastica\Document();
        $dateFields = array('createdon','editedon','deletedon','publishedon');
        foreach ($fields as $fieldName => $value) {
            if (is_string($fieldName) && !is_array($value) && !is_object($value)) {
                if (in_array($fieldName,$dateFields)) {
                    $value = ''.strftime('%Y-%m-%dT%H:%M:%SZ',strtotime($value));
                    $fields[$fieldName] = $value;
                }
                if($fieldName == 'id'){
                    $document->setId($value);
                }
                if($fieldName == 'content') {
                    $value = strip_tags($value, '<p>,<h1>,<h2>,<h3>,<h4>,<em>,<strong>,<br>');
                }
                $document->set($fieldName,$value);
            }
        }
        $this->modx->log(modX::LOG_LEVEL_DEBUG,'[SimpleSearch] Indexing Resource: '.print_r($fields,true));

        $response = $type->addDocument($document);

        $type->getIndex()->refresh();
        return $response->isOk();
    }

    /**
     * Remove a Resource from the ElasticSearch index.
     *
     * @param string|int $id
     * @return boolean
     */
    public function removeIndex($id) {
        $this->modx->log(modX::LOG_LEVEL_DEBUG,'[SimpleSearch] Removing Resource From Index: '.$id);

        /** @var modResource $resource */
        $resource = $this->modx->getObject('modResource', $id);
        if ($resource) {
            $typeName = $resource->context_key;
        } else {
            $typeName = 'web';
        }

        $type = $this->index->getType($typeName);

        try {
            $type->deleteById($id);
        } catch (Exception $e) {}
        
        $type->getIndex()->refresh();
    }
}
