<?php
namespace SimpleSearch\Driver;

use PDO;
use MODX\Revolution\modResource;
use MODX\Revolution\modTemplateVar;
use MODX\Revolution\modTemplateVarResource;
use xPDO\Om\xPDOQuery;

/**
 * Standard sql-based search driver for SimpleSearch
 *
 * @package simplesearch
 */
class SimpleSearchDriverBasic extends SimpleSearchDriver
{
    public string $searchString;

    public function initialize(): bool
    {
        return true;
    }

    public function index(array $fields): bool
    {
        return true;
    }

    public function removeIndex($id): bool
    {
        return true;
    }

    /**
     * @param string $string
     * @param array $scriptProperties
     * @return array
     */
    public function search($string, array $scriptProperties = array()) {

        if (!empty($string)) {
            $this->searchString = strip_tags($this->modx->sanitizeString($string));
        }

        $ids           = $this->modx->getOption('ids', $scriptProperties, '');
        $exclude       = $this->modx->getOption('exclude', $scriptProperties, '');
        $useAllWords   = $this->modx->getOption('useAllWords', $scriptProperties, false);
        $searchStyle   = $this->modx->getOption('searchStyle', $scriptProperties, 'partial');
        $hideMenu      = (int) $this->modx->getOption('hideMenu', $scriptProperties, 2);
        $maxWords      = $this->modx->getOption('maxWords', $scriptProperties, 7);
        $andTerms      = $this->modx->getOption('andTerms', $scriptProperties, true);
        $matchWildcard = $this->modx->getOption('matchWildcard', $scriptProperties, true);
        $docFields     = explode(',', $this->modx->getOption('docFields', $scriptProperties, 'pagetitle,longtitle,alias,description,introtext,content'));
        $includeTVs    = $this->modx->getOption('includeTVs', $scriptProperties, false);
        $includeTVList = $this->modx->getOption('includeTVList', $scriptProperties, '');
        $includedTVIds = array();


        $c = $this->modx->newQuery(modResource::class);
        if ($includeTVs) {
            $c->leftJoin(modTemplateVarResource::class, 'TemplateVarResources');
            if (!empty($includeTVList)) {
                $includeTVList = explode(',', $includeTVList);
                $includeTVList = array_map('trim', $includeTVList);
                $tv = $this->modx->newQuery(modTemplateVar::class, [
                    'name:IN' => $includeTVList
                ]);
                $tv->select('id');
                $tv->prepare();
                $result = $this->modx->query($tv->toSQL());
                $tvIds = $result->fetchAll(PDO::FETCH_ASSOC);
                foreach ($tvIds as $row) {
                    $includedTVIds[] = $row['id'];
                }
            }
        }

        /* If using customPackages, add here */
        $customPackages = array();
        if (!empty($scriptProperties['customPackages'])) {
            $packages = explode('||', $scriptProperties['customPackages']);
            if (is_array($packages) && !empty($packages)) {
                $searchArray = array(
                    '{core_path}',
                    '{assets_path}',
                    '{base_path}',
                );

                $replacePaths = array(
                    $this->modx->getOption('core_path', null, MODX_CORE_PATH),
                    $this->modx->getOption('assets_path', null, MODX_ASSETS_PATH),
                    $this->modx->getOption('base_path', null, MODX_BASE_PATH),
                );
                foreach ($packages as $package) {
                    /* 0: class name, 1: field name(s) (csl), 2: package name, 3: package path, 4: criteria */
                    $package = explode(':', $package);
                    if (!empty($package[4])) {
                        $package[3] = str_replace($searchArray, $replacePaths, $package[3]);

                        $className = $package[0];
                        $classAlias = $this->modx->getAlias($className);

                        if (!class_exists($className)){
                            $this->modx->addPackage($package[2], $package[3]);
                        }

                        $c->leftJoin($className, $classAlias, $package[4]);

                        $customPackages[] = $package;
                    }
                }
            }
        }

        /* Process conditional clauses */
        if ($searchStyle === 'partial' || $this->modx->config['dbtype'] === 'sqlsrv') {
            $wildcard   = $matchWildcard ? '%' : '';
            $whereArray = [];
            $i = 1;

            $searchTerms = [ $this->searchString ];
            if (empty($useAllWords)) {
                $searchTerms = $this->search->searchArray;
            }

            foreach ($searchTerms as $term) {
                if ($i > $maxWords) {
                    break;
                }

                $whereArrayKeys = [];
                $whereArrayValues = [];
                $term = $wildcard . $term . $wildcard;

                foreach ($docFields as $field) {
                    $whereArrayKeys[] = 'OR:' . $field . ':LIKE';
                    $whereArrayValues[] = $term;
                }

                if ($includeTVs) {
                    $whereArrayKeys[] = 'OR:TemplateVarResources.value:LIKE';
                    $whereArrayValues[] = $term;
                    if (!empty($includeTVList)) {
                        $whereArrayKeys[] = 'AND:TemplateVarResources.tmplvarid:IN';
                        $whereArrayValues[] = $includedTVIds;
                    }
                }

                if (is_array($customPackages) && !empty($customPackages)) {
                    foreach ($customPackages as $package) {
                        $fields = explode(',', $package[1]);
                        foreach ($fields as $field) {
                            $classAlias = $this->modx->getAlias($package[0]);
                            $whereArrayKeys[] = 'OR:' . $classAlias . '.' . $field . ':LIKE';
                            $whereArrayValues[] = $term;
                        }
                    }
                }

                if (count($whereArrayKeys) > 0) {
                    if ($andTerms) {
                        $whereArrayKeys[0] = preg_replace('/^OR:/', '', $whereArrayKeys[0]);
                    }
                    $whereArray[] = array_combine($whereArrayKeys, $whereArrayValues);
                }

                $i++;
            }

            $c->where($whereArray);
        } else {
            $fields = $this->modx->getSelectColumns(modResource::class, '', '', $docFields);
            if (is_array($customPackages) && !empty($customPackages)) {
                foreach ($customPackages as $package) {
                    $classAlias = $this->modx->getAlias($package[0]);
                    $fields .= (!empty($fields) ? ',' : '') . $this->modx->getSelectColumns($package[0], $classAlias, '', explode(',', $package[1]));
                    if (!empty($package[4])) {
                        $c->where($package[4]);
                    }
                }
            }

            $wildcard       = $matchWildcard ? '*' : '';
            $relevancyTerms = array();
            if (empty($useAllWords)) {
                $i = 0;
                foreach ($this->search->searchArray as $term) {
                    if ($i > $maxWords) {
                        break;
                    }

                    $relevancyTerms[] = $this->modx->quote($term . $wildcard);

                    $i++;
                }
            } else {
                $relevancyTerms[] = $this->modx->quote($string . $wildcard);
            }

            $this->addRelevancyCondition($c,
                array(
                    'class'  => 'modResource',
                    'fields' => $fields,
                    'terms'  => $relevancyTerms
                )
            );
        }

        if (!empty($ids)) {
            $idType = $this->modx->getOption('idType', $this->config, 'parents');
            $depth  = $this->modx->getOption('depth', $this->config, 10);
            $ids    = $this->processIds($ids, $idType, $depth);
            if (!empty($exclude)) {
                $exclude = $this->cleanIds($exclude);
                /* No need to build 'NOT IN' array because we will remove these from the 'IN' array */
                /* $c->where(array("{$f}:NOT IN" => explode(',', $exclude)),xPDOQuery::SQL_AND,null,2); */

                $ids = array_diff($ids, explode(',', $exclude));
            }

            $f = $this->modx->getSelectColumns(modResource::class, 'modResource', '', array('id'));

            $c->where(["$f:IN" => $ids]);
        }

        $c->where(['published:=' => 1]);
        $c->where(['searchable:=' => 1]);
        $c->where(['deleted:=' => 0]);

        /* Restrict to either this context or specified contexts */
        $ctx = !empty($this->config['contexts']) ? $this->config['contexts'] : $this->modx->context->get('key');
        $f   = $this->modx->getSelectColumns(modResource::class, 'modResource','', array('context_key'));
        $c->where(["$f:IN" => explode(',', $ctx)]);

        if ($hideMenu !== 2) {
            $c->where(['hidemenu' => $hideMenu === 1]);
        }

        $total = $this->modx->getCount(modResource::class, $c);

        $c->query['distinct'] = 'DISTINCT';
        if (!empty($scriptProperties['sortBy'])) {
            $sortDir  = $this->modx->getOption('sortDir', $scriptProperties, 'DESC');
            $sortDirs = explode(',', $sortDir);
            $sortBys  = explode(',', $scriptProperties['sortBy']);
            $dir      = 'desc';
            for ($i = 0, $iMax = count($sortBys); $i < $iMax; $i++) {
                if (isset($sortDirs[$i])) {
                    $dir = $sortDirs[$i];
                }

                $c->sortby('modResource.' . $sortBys[$i], strtoupper($dir));
            }
        }

        $resources = $this->modx->getCollection(modResource::class, $c);
        if (empty($scriptProperties['sortBy'])) {
            $resources = $this->sortResults($resources, $scriptProperties);
        }

        /* Set limit */
        $perPage = (int) $this->modx->getOption('perPage', $this->config, 10);
        if ($perPage > 0) {
            $offset      = $this->modx->getOption('start', $this->config, 0);
            $offsetIndex = $this->modx->getOption('offsetIndex', $this->config, 'simplesearch_offset');

            if (isset($_REQUEST[$offsetIndex])) {
                $offset = (int) $_REQUEST[$offsetIndex];
            }

            $resources = array_slice($resources, $offset, $perPage);
        }

        $includeTVs = $this->modx->getOption('includeTVs', $scriptProperties, '');
        $processTVs = $this->modx->getOption('processTVs', $scriptProperties, '');
        $tvPrefix   = $this->modx->getOption('tvPrefix', $scriptProperties, '');
        $list       = array();

        /** @var modResource $resource */
        foreach ($resources as $resource) {
            if (!$resource->checkPolicy('list')) {
                continue;
            }

            $resourceArray = $resource->toArray();
            if (!empty($includeTVs)) {
                $templateVars =& $resource->getMany('TemplateVars');
                /** @var modTemplateVar $templateVar */
                foreach ($templateVars as $tvId => $templateVar) {
                    $resourceArray[$tvPrefix . $templateVar->get('name')] = !empty($processTVs) ? $templateVar->renderOutput($resource->get('id')) : $templateVar->get('value');
                }
            }

            $list[] = $resourceArray;
        }

        return array(
            'total'   => $total,
            'results' => $list,
        );
    }

    /**
     * add relevancy search criteria to query
     *
     * @param xPDOQuery $query
     * @param array $options ['terms'] search terms (will only be one array member if useAllWords parameter is set)
     * @return boolean
     */
    public function addRelevancyCondition(xPDOQuery &$query, array $options): bool
    {
        $fields = $this->modx->getOption('fields', $options, '');
        $terms  = $this->modx->getOption('terms', $options, array());

        if (!empty($fields)) {
            foreach($terms as $term) {
                $query->where("MATCH ( {$fields} ) AGAINST ( {$term} IN BOOLEAN MODE )");
            }
        }

        return true;
    }
}
