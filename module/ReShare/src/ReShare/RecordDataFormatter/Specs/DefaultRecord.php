<?php

/**
 * Record-data formatter specification for ReShare records.
 */

namespace ReShare\RecordDataFormatter\Specs;

use VuFind\View\Helper\Root\RecordDataFormatter\SpecBuilder;

/**
 * Add linked access restrictions to VuFind's default record metadata.
 */
class DefaultRecord extends \VuFind\RecordDataFormatter\Specs\DefaultRecord
{
    /**
     * Get core metadata fields.
     *
     * @return array
     */
    protected function getDefaultCoreSpecs(): array
    {
        $spec = new SpecBuilder(parent::getDefaultCoreSpecs());
        $spec->setTemplateLine(
            'Access',
            'getAccessRestrictions',
            'data-genericLink.phtml'
        );
        return $spec->getArray();
    }

    /**
     * Get description-tab fields without duplicating access restrictions.
     *
     * @return array
     */
    protected function getDefaultDescriptionSpecs(): array
    {
        $spec = parent::getDefaultDescriptionSpecs();
        unset($spec['Access']);
        return $spec;
    }
}
