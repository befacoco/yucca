<?php
namespace Yucca\Component\Mapping;

use Yucca\Component\SourceManager;
use Yucca\Component\Source\Exception\NoDataException;

class Mapper {

    protected $name;
    protected $configuration;
    protected $configurationIsInitialized;

    /**
     * @var \Yucca\Component\SourceManager
     */
    protected $sourceManager;


    /**
     * Constructor
     * @param string $name
     * @param array $configuration
     */
    public function __construct($name, array $configuration=array()) {
        $this->name = $name;
        $this->configuration = $configuration;
    }

    /**
     * @param \Yucca\Component\SourceManager $sourceManager
     */
    public function setSourceManager(SourceManager $sourceManager){
        $this->sourceManager = $sourceManager;
    }

    public function getFieldNameFromProperty($propertyName){
        $field = $propertyName;
        if(isset($this->configuration['properties'][$propertyName]['field'])){
            $field = $this->configuration['properties'][$propertyName]['field'];
        }

        return $field;
    }

    /**
     * Load datas for specified propertyName and identifier
     * @param array $identifier
     * @param string $propertyName
     * @throws \Exception
     * @throws \RuntimeException
     * @return array
     */
    public function load(array $identifier, $propertyName){
        if(isset($identifier[$propertyName])){
            return array($propertyName=>$identifier[$propertyName]);
        }

        $field = $this->getFieldNameFromProperty($propertyName);

        $sources = $this->getSourcesFromPropertyName($propertyName);

        foreach($sources as $sourceName){
            $source = $this->sourceManager->getSource($sourceName);
            if($source->canHandle($field)){
                $mappedIdentifiers = array();
                foreach($identifier as $id=>$value){
                    $mappedIdentifiers[$this->getFieldNameFromProperty($id)] = $value;
                }

                $datas = $source->load($mappedIdentifiers);

                $mappedDatas = $this->mapFieldsToProperties($datas, $sourceName);

                return $mappedDatas;
            }
        }

        throw new \Exception("No source support field $field from property $propertyName in {$this->name}");
    }

    protected function mapFieldsToProperties($datas, $sourceName){
        $mappedDatas = array();
        foreach($datas as $dataKey=>$dataValue) {
            $mappedDatas[$this->getPropertyNameFromField($dataKey, $sourceName)] = $dataValue;
        }

        return $mappedDatas;
    }

    public function save($identifier, array $propertyValues){
        if(false == is_array($identifier)){
            $identifier = array();
        }

        //First, create datas to save for each sources
        $datasBySource = array();
        foreach($propertyValues as $propertyName => $value) {
            //Get sources for the current property
            $sources = $this->getSourcesFromPropertyName($propertyName);

            //Get field name of this property
            $field = $this->getFieldNameFromProperty($propertyName);

            //Foreach sources, if it can handle the field, save to it (should only appear once)
            $isFirstSource = true;
            foreach($sources as $sourceName){
                $source = $this->sourceManager->getSource($sourceName);
                if($source->canHandle($field) && (false === $source->isIdentifier($field) || false === $isFirstSource)) {
                    $datasBySource[$sourceName][$field] = $value;
                }
                $isFirstSource = false;
            }
        }

        //Now, map identifiers to fields
        $mappedIdentifiers = array();
        foreach($identifier as $propertyName => $value) {
            //Get sources for the current identifier
            $sources = $this->getSourcesFromPropertyName($propertyName);

            //Get field name of this identifier
            $field = $this->getFieldNameFromProperty($propertyName);

            //Foreach sources, if it can handle the field, save to it (should only appear once)
            foreach($sources as $sourceName){
                $source = $this->sourceManager->getSource($sourceName);
                if($source->canHandle($field)) {
                    $mappedIdentifiers[$sourceName][$field] = $value;
                }
            }
        }

        //order $datasBySource by reverse order of default sources
        $orderedDatasBySource = array();
        $sourceNames = $this->configuration['sources'];
        foreach($sourceNames as $sourceName) {
            if(isset($datasBySource[$sourceName])) {
                $orderedDatasBySource[$sourceName] = $datasBySource[$sourceName];
            }
        }

        //loop on datas to save them to each sources
        $createdFieldValues = array();
        $mappedNewFields = array();
        foreach($orderedDatasBySource as $sourceName=>$datas) {
            $source = $this->sourceManager->getSource($sourceName);

            $newIdentifiers = array();
            foreach($createdFieldValues as $field=>$value) {
                if($source->canHandle($field)){
                    $newIdentifiers[$field] = $value;
                }
            }
            $justCreatedFieldValues = $source->save(array_merge($datas,$newIdentifiers), isset($mappedIdentifiers[$sourceName]) ? $mappedIdentifiers[$sourceName] : array());
            $createdFieldValues = array_merge($createdFieldValues, $justCreatedFieldValues);
            $mappedNewFields = array_merge($this->mapFieldsToProperties($justCreatedFieldValues, $sourceName),$mappedNewFields);
        }

        return $mappedNewFields;
    }

    public function remove($identifier){
        $sources = array();
        foreach($this->configuration['properties'] as $properties) {
            if(isset($properties['sources'])) {
                foreach($properties['sources'] as $sourceName) {
                    if(false === isset($sources[$sourceName])) {
                        $sources[$sourceName] = $this->sourceManager->getSource($sourceName);
                    }
                }
            }
        }
        foreach($this->configuration['sources'] as $sourceName) {
            if(false === isset($sources[$sourceName])) {
                $sources[$sourceName] = $this->sourceManager->getSource($sourceName);
            }
        }

        $mappedIdentifiers = array();
        foreach($identifier as $id=>$value){
            $mappedIdentifiers[$this->getFieldNameFromProperty($id)] = $value;
        }

        foreach($sources as $source) {
            $source->remove($mappedIdentifiers);
        }

        return $this;
    }

    protected function getSourcesFromPropertyName($propertyName) {
        if(isset($this->configuration['properties'][$propertyName]['sources'])){
            $sources = $this->configuration['properties'][$propertyName]['sources'];
        } else {
            if(isset($this->configuration['sources'])) {
                $sources = $this->configuration['sources'];
            } else {
                throw new \Exception("No sources defined for mapper {$this->name}");
            }
        }

        return $sources;
    }

    /**
     * @param $field
     * @param $sourceName
     * @return int|string
     */
    protected function getPropertyNameFromField($field, $sourceName) {
        foreach($this->configuration['properties'] as $propertyName => $properties) {
            if(isset($properties['field']) && $field == $properties['field']) {
                if(isset($properties['sources'])) {
                    if(in_array($sourceName,$properties['sources'])) {
                        return $propertyName;
                    }
                } else {
                    if(in_array($sourceName,$this->configuration['sources'])) {
                        return $propertyName;
                    }
                }
            }
        }

        return $field;
    }
}