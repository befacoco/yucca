<?php
namespace Yucca\Component\Source;

abstract class SourceAbstract implements SourceInterface
{
    protected $configuration;
    protected $sourceName;

    /**
     * Constructor
     * @param $sourceName
     * @param array $configuration
     */
    public function __construct($sourceName, array $configuration=array()) {
        $this->configuration = $configuration;
        $this->sourceName = $sourceName;
    }

    /**
     * @param $fieldName
     * @return bool
     */
    public function canHandle($fieldName){
        return array_key_exists($fieldName, $this->configuration['fields']);
    }

    /**
     * @param $fieldName
     * @return bool
     */
    public function isIdentifier($fieldName){
        return isset($this->configuration['fields'][$fieldName]['type']) && ('identifier'===$this->configuration['fields'][$fieldName]['type']);
    }
}
