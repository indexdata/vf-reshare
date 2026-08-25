<?php

/**
 * Record-data formatter specification for Dublin Core ReShare records.
 */

namespace ReShare\RecordDataFormatter\Specs;

/**
 * Dublin Core descriptions are already displayed with the core metadata.
 */
class DublinCore extends DefaultRecord
{
    /**
     * Suppress the duplicate description section.
     *
     * @return array
     */
    protected function getDefaultDescriptionSpecs(): array
    {
        return [];
    }
}
