<?php

use ReShare\RecordDataFormatter\Specs\DefaultRecord as DefaultRecordSpecs;
use ReShare\RecordDataFormatter\Specs\DublinCore as DublinCoreSpecs;
use VuFind\RecordDataFormatter\Specs\DefaultRecordFactory;

return array (
  'vufind' => 
  array (
    'plugin_managers' => 
    array (
      'ils_driver' => 
      array (
        'factories' => 
        array (
          'ReShare\\ILS\\Driver\\ReShare' => 'ReShare\\ILS\\Driver\\ReShareFactory',
        ),
        'aliases' => 
        array (
          'reshare' => 'ReShare\\ILS\\Driver\\ReShare',
        ),
      ),
      'recorddriver' => 
      array (
        'factories' => 
        array (
          'ReShare\\RecordDriver\\SolrDefault' => 'VuFind\\RecordDriver\\SolrDefaultFactory',
          'ReShare\\RecordDriver\\SolrMarc' => 'VuFind\\RecordDriver\\SolrDefaultFactory',
        ),
        'aliases' => 
        array (
          'VuFind\\RecordDriver\\SolrDefault' => 'ReShare\\RecordDriver\\SolrDefault',
          'VuFind\\RecordDriver\\SolrMarc' => 'ReShare\\RecordDriver\\SolrMarc',
        ),
        'delegators' => 
        array (
          'ReShare\\RecordDriver\\SolrMarc' => 
          array (
            0 => 'ReShare\\RecordDriver\\IlsAwareDelegatorFactory',
          ),
        ),
      ),
      'auth' => 
      array (
        'factories' => 
        array (
          'ReShare\\Auth\\ReShare' => 'ReShare\\Auth\\ReShareFactory',
        ),
        'aliases' => 
        array (
          'reshare' => 'ReShare\\Auth\\ReShare',
          'ReShare' => 'ReShare\\Auth\\ReShare',
        ),
      ),
      'recorddataformatter_specs' =>
      array (
        'factories' =>
        array (
          DefaultRecordSpecs::class => DefaultRecordFactory::class,
          DublinCoreSpecs::class => DefaultRecordFactory::class,
        ),
      ),
    ),
  ),
  'controllers' => [
      'factories' => [
          'ReShare\Controller\MyResearchController' => \VuFind\Controller\MyResearchControllerFactory::class,
      ],
      'aliases' => [
          'VuFind\Controller\MyResearchController' => 'ReShare\Controller\MyResearchController',
      ],
  ],
);
