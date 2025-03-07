Changelog for SimpleSearch.

SimpleSearch 3.1.0
========================================================================
- Fix toPlaceholder property
- Fix fatal error when content field is NULL
- Fix property "exclude"
- Limit result with SQL if property "sortBy" is used
- Check if exact text is set to prevent breaking of results.
- Add the option to exclude/include results based on templates
- Fix "ids" property, if it contains IDs from different contexts
- Automatically remove outdated SimpleSearchIndexer plugin if it exists

SimpleSearch 3.0.0
========================================================================
- Added support for MODX3

SimpleSearch 2.1.2
========================================================================
- Show facet results when default has none.
- Have all placeholders in no result page
- Fix pagination for pageLimit & facet search

SimpleSearch 2.1.1
========================================================================
- Fix includeTVList bug to show normal search results as well.

SimpleSearch 2.1.0
========================================================================
- Added pdoParser support
- Fix XSS security issue

SimpleSearch 2.0.1
========================================================================
- Fixed so that includedTVs can also be boosted PR #1
- Fixed condition for leftJoin of query to only join includeTVList TemplateVars when property is set. PR #5. Thanks to travisbotello
- Fixed issue with currentPageTpl. PR #6. Thanks to travisbotello.

SimpleSearch 2.0.0
========================================================================
- Removed elasticsearch and solr driver
- Renamed namespace sisea to simplesearch
- Fixed issue where boosting would only be applied to paginated results
- Added processor for returning a search autosuggest list

SimpleSearch 1.9.2
========================================================================
- Generate extract for whole search string, not just for last part
- Added option to add search term into the link
- Fixed generating extracts

SimpleSearch 1.9.1
========================================================================
- Fixed indexing resource when it's not published or deleted
- Fixed error with redeclared function in plugin
- Added includeTVList parameter

SimpleSearch 1.9.0
========================================================================
- Added extractSource parameter - names the field for the extract, or a snippet to run (which is passed the resource array as properties)

SimpleSearch 1.8.1
========================================================================
- Added snippet for definition of ElasticSearch index (duplicate it and remove the _default from name to prevent recreating after update)

SimpleSearch 1.8.0
========================================================================
- Added support to ElasticSearch driver for using search fields
- Added support to ElasticSearch driver for boosting results by a field value
- Updated Elastica to v0.90.7.0
- [#84] Czech localization
- [#78] Japanese localization
- [#77] Enable $tvPrefix to prefix TV placeholders
- [#2] Do not search TV values if includeTVs != 1 (modxcms/SimpleSearch#2)
- [#1] Fixed SQL for "exclude" parameter (modxcms/SimpleSearch#1)
- Added driver for ElasticSearch
- Added support to ElasticSearch driver for using search fields
- Added support to ElasticSearch driver for boosting results by a field value
- Updated Elastica to v0.90.7.0
- Added outputSeparator option

SimpleSearch 1.7.0
========================================================================
- Added driver for ElasticSearch

SimpleSearch 1.6.1
========================================================================
- [#69] Apply additional guard against injection of modx tags

SimpleSearch 1.6.0
========================================================================
- [#65] Fix issue with multiple search terms highlighting
- [#67] Fix issue with quotations breaking forms in SimpleSearchForm snippet
- [#64] Improved pagination links, added pageLimit and optional extra tpls

SimpleSearch 1.5.2
========================================================================
- Fix invalid mb_strlen call when should be strlen
- [#60] Fix issue with search highlighting

SimpleSearch 1.5.1
========================================================================
- [#55] Fix issue with offsets in multibyte, one-character searches
- [#59] Fix issue with broken links on pagination

SimpleSearch 1.5.0
========================================================================
- [#36] Fix issue where whereGroup is not incr when using useAllWords
- Allow file-based chunks in all tpl chunks
- [#53] Ensure list permission is checked on loading of resources
- [#56] Fix issue with forward slashes in searches

SimpleSearch 1.4.1
========================================================================
- Ensure Solr search gets TVs indexed

SimpleSearch 1.4.0
========================================================================
- Improved TV indexing for Solr-based searches, all TVs are now pre-processed and included during index
- Fixed bug with processTVs in basic search
- [#49] Add sortBy and sortDir properties for sorting of results
- [#48] Fix issue with internationalization of no results message
- [#50] Fix issue with pagination and searches with spaces
- Added Solr search implementation, split up search into driver-specific classes
- [#37] Fix charset in German translation
- [#46] Add tpl for no results message, in property noResultsTpl

SimpleSearch 1.3.0
========================================================================
- Fix bug in simplesearchform.snippet where spaces were being removed from searchValue
- Fix SqlSrv query issues
- Add sisea.facet placeholder for results page, and 'search' parameter to be sent into posthooks
- Add faceted search ability through postHooks
- Add facetLimit to limit # of non-active facet results that return on main results page

SimpleSearch 1.2.0
========================================================================
- Fixed bug with pagination
- Fixed bug with searchScore sorting
- Fixed bug with progressive highlighting class names
- Added 'alias' to default docFields
- Added fieldPotency property to allow certain fields higher search scores for the same match
- Added result sorting (by searchScores)

SimpleSearch 1.1.2
========================================================================
- Added the urlScheme parameter which allows you to control the URL scheme used
  for the pagination links.
- Made the result extracts to never break words. This means that the extract
  limit is a soft limit now, and results look much nicer.
- Added the query placeholder to the containerTpl chunk.
- Fixed the default value of the landing parameter for the SimpleSearchForm
  snippet.
- Fixed an URL decoding bug.
- Removed defaults from the provided chunk templates, as they duplicate code
  from the snippets.
- Made minor performance improvements.

SimpleSearch 1.1.1
========================================================================
- Fixed bug in snippet with wrong reference to $modx var
- Added idx placeholder
- Added German translation
- Added andTerm (true/false) - Whether or not to add a logical AND between words in search term.
- Added matchWildcard (behavior depending on query type and limitations)
- Added docFields which is a list of fields to search on the Resource.

SimpleSearch 1.1.0
========================================================================
- Added ability to use TVs in placeholders
- Added ability to exclude resources
- Added missing f parameter if ids was empty
- Added longtitle to partial search style
- Fixed XHTML get/post in form tpl issue

SimpleSearch 1.0.0
========================================================================
- Now searches unprocessed TVs
- Added &customPackages property for searching custom packages
- Added fix for GET searches
- Added sisea.query and sisea.count global placeholders for search count/query string
- Fixed bug with multibyte chars
- Fixed bug with contexts param that was leaving search results empty
- Fixed bug with separators doubling
- Fixed bug with perPage not being respected due to shared object instance
- Added Russian translation
- Prepared for initial 1.0-beta release
- Adjusted toPlaceholder behavior through output() method
- Created build script
- Initial commit
