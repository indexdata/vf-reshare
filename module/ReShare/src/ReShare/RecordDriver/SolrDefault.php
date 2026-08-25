<?php

namespace ReShare\RecordDriver;

use ReShare\RecordDataFormatter\Specs\DefaultRecord as DefaultRecordSpecs;
use ReShare\RecordDataFormatter\Specs\DublinCore as DublinCoreSpecs;

class SolrDefault extends \VuFind\RecordDriver\SolrDefault
{
    /**
     * Get the indexed record format.
     *
     * @return string
     */
    public function getRecordFormat()
    {
        return $this->fields['record_format'] ?? '';
    }

    /**
     * Get the physical extent in the record.
     *
     * @return false
     */
    public function getPhysicalExtent()
    {
        return false;
    }

    /**
     * Get the VuFind 11 record-data formatter specification for this record.
     *
     * @return string
     */
    public function getRecordDataFormatterSpecClass(): ?string
    {
        return in_array(strtolower($this->getRecordFormat()), ['dc', 'qdc'], true)
            ? DublinCoreSpecs::class
            : DefaultRecordSpecs::class;
    }
}
