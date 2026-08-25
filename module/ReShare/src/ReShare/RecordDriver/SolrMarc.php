<?php

namespace ReShare\RecordDriver;

use ReShare\RecordDataFormatter\Specs\DefaultRecord as DefaultRecordSpecs;

class SolrMarc extends \VuFind\RecordDriver\SolrMarc
{
    use \ReShare\RecordDriver\Feature\IlsAwareTrait;

    protected $openUrlReferrerId;
    protected $nonRequestableIdPrefixes = [];

    public function get999p()
    {
        return $this->getFieldArray('999', ['p']);
    }

    public function setOpenUrlReferrerId($openUrlReferrerId)
    {
        $this->openUrlReferrerId = $openUrlReferrerId;
    }

    public function setNonRequestableIdPrefixes($prefixes)
    {
        $this->nonRequestableIdPrefixes = $prefixes;
    }

    /**
     * Get the VuFind 11 record-data formatter specification for ReShare records.
     *
     * @return string
     */
    public function getRecordDataFormatterSpecClass(): ?string
    {
        return DefaultRecordSpecs::class;
    }

    /**
     * Assert that records that cannot be requested do not support OpenURL
     *
     * Needed so the "Request" button doesn't appear for records with a configured
     * identifier prefix or a digital format.
     * Otherwise, defer to the parent.
     *
     * @return bool
     */
    public function supportsOpenUrl()
    {
        $recordId = $this->fields['id'] ?? '';
        foreach ($this->nonRequestableIdPrefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($recordId, $prefix)) {
                return false;
            }
        }

        $formats_to_suppress_requests = ['Electronic', 'eBook'];
        foreach ($this->getFormats() as $element) {
            if (in_array($element, $formats_to_suppress_requests)) {
                return false;
            }
        }

        return parent::supportsOpenUrl();
    }

    /**
     * Get the full title of the record.
     *
     * Overrides parent method to add subfields 'n' and 'p'
     *
     * @return string
     */
    public function getTitle()
    {
        return rtrim($this->getFirstFieldValue('245', ['a', 'b', 'n', 'p']), " /");
    }

    /**
     * Get the MARC 300a description of the record.
     *
     * @return string
     */
    public function getPhysicalExtent()
    {
        return rtrim($this->getFirstFieldValue('300', ['a']));
    }

    public function isLendable()
    {
        $lending_statuses = $this->get999p() ?? [];
        return in_array("LOANABLE", $lending_statuses);
    }

    public function getDefaultOpenUrlParams()
    {
        // Get a representative publication date:
        $pubDate = $this->getPublicationDates();
        $pubDate = empty($pubDate) ? '' : $pubDate[0];

        $symbol = $this->college;
        $reqID = $this->patronID;

        $openURLParams = [
            'req_id'    => $reqID,
            'rft_id'    => $this->getUniqueID(),
            'url_ver'   => 'Z39.88-2004',
            'ctx_ver'   => 'Z39.88-2004',
            'ctx_enc'   => 'info:ofi/enc:UTF-8',
            'rfr_id'    => $this->openUrlReferrerId,
            'rft.title' => $this->getTitle(),
            'rft.date'  => $pubDate,
            'res.org'   => $symbol,
        ];

        if ($oclc = $this->getOCLC()[0] ?? null) {
            $openURLParams['rft.oclc'] = $oclc;
        }

        if ($isbn = $this->getCleanISBN()) {
            $openURLParams['rft.isbn'] = (string)$isbn;
        }

        return $openURLParams;
    }

    /**
     * Get access restriction notes for the record.
     *
     * @return array
     */
    public function getAccessRestrictions()
    {
        $resultArray = [];
        $marc506au = $this->getFieldArray('506', ['a', 'u'], false);
        if (count($marc506au) % 2 !== 0) {
            $marc506au[] = '';
        }

        $groupedMarc506s = [];
        for ($i = 0; $i < count($marc506au); $i += 2) {
            $groupedMarc506s[] = ['a' => $marc506au[$i], 'u' => $marc506au[$i + 1]];
        }

        foreach ($groupedMarc506s as $field) {
            $result['text'] = $field['a'] ?? '';
            $result['url'] = $field['u'] ?? '';
            $resultArray [] = $result;
        }
        return $resultArray;
    }

    /**
     * Add record context to MARC parsing errors.
     *
     * @return \VuFind\Marc\MarcReader
     */
    public function getMarcReader()
    {
        try {
            return parent::getMarcReader();
        } catch (\Throwable $e) {
            $id = $this->getUniqueID();

            throw new \RuntimeException(
                sprintf(
                    'Unable to parse MARCXML for record %s: %s',
                    $id,
                    $e->getMessage()
                ),
                0,
                $e
            );
        }
    }
}
