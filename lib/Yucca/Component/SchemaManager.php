<?php
namespace Yucca\Component;

use Yucca\Component\ConnectionManager;
use Yucca\Component\ShardingStrategy\ShardingStrategyInterface;

class SchemaManager
{
    protected $schemaConfig;
    protected $shardingStrategies;
    /**
     * @var Yucca\Component\ConnectionManager
     */
    protected $connectionManager;

    /**
     * @param array $schemaConfig
     */
    public function __construct(array $schemaConfig) {
        $this->schemaConfig = $schemaConfig;
    }

    /**
     * @param ConnectionManager $connectionManager
     */
    public function setConnectionManager(ConnectionManager $connectionManager){
        $this->connectionManager = $connectionManager;
    }

    /**
     * @param $shardingStrategyName
     * @param ShardingStrategy\ShardingStrategyInterface $shardingStrategy
     */
    public function addShardingStrategy($shardingStrategyName, ShardingStrategyInterface $shardingStrategy) {
        $this->shardingStrategies[$shardingStrategyName] = $shardingStrategy;
    }

    /**
     * @param $table
     * @param $shardingKey
     * @param bool $forceFromMaster
     * @return mixed
     * @throws \Exception
     */
    public function getConnectionName($table, $shardingKey, $forceFromMaster=true) {
        $shardingIdentifier = $this->getShardingIdentifier($table, $shardingKey);

        if(is_null($shardingIdentifier)){
            if(1 === count($this->schemaConfig[$table]['shards'])){
                return current($this->schemaConfig[$table]['shards']);
            } else {
                $shardsCount = count($this->schemaConfig[$table]['shards']);
                throw new \Exception("Table $table is not configured as sharded. $shardsCount connections found for table $table and sharding key $shardingKey");
            }
        }

        if(false === isset($this->schemaConfig[$table]['shards'][$shardingIdentifier])) {
            throw new \Exception("No connections found for table $table and shard $shardingIdentifier");
        }

        //Return connection name
        return $this->schemaConfig[$table]['shards'][$shardingIdentifier];
    }

    public function getShardingIdentifier($table, $shardingKey = null){
        if(is_null($shardingKey)){
            return null;
        }

        //Look for table config
        if(false === isset($this->schemaConfig[$table])) {
            throw new \InvalidArgumentException("$table is not in given configuration");
        }

        //Look for sharding strategy
        if(false === isset($this->schemaConfig[$table]['sharding_strategy'])){
            return null;
        }
        $shardingStrategy = $this->schemaConfig[$table]['sharding_strategy'];
        if(false === isset($this->shardingStrategies[$shardingStrategy])){
            throw new \Exception("Sharding strategy $shardingStrategy not found for table $table");
        }

        //Look for sharding identifier
        return $this->shardingStrategies[$shardingStrategy]->getShardingIdentifier(
            $this->schemaConfig[$table],
            $shardingKey
        );
    }

    protected function fetch($tableName, array $criterias, array $fields, $allowEmptyCriterias, $forceFromMaster, array $options=array()) {
        $shardingKey = null;
        if(isset($criterias['sharding_key'])) {
            $shardingKey = $criterias['sharding_key'];
            unset($criterias['sharding_key']);
        }

        if((false === $allowEmptyCriterias) && empty($criterias)){
            throw new \Exception("Trying to load from $tableName with no identifiers");
        }

        $connection = $this->connectionManager->getConnection(
            $this->getConnectionName($tableName, $shardingKey, $forceFromMaster),
            $forceFromMaster
        );

        $shardingIdentifier = $this->getShardingIdentifier($tableName,$shardingKey);
        if($shardingIdentifier) {
            $tableName = sprintf('%1$s_%2$s', $tableName, $shardingIdentifier);
        }

        $fields = implode(',',$fields);

        $sql = "SELECT $fields FROM `$tableName`";
        $params = array();
        $whereCriterias = array();
        foreach($criterias as $criteriaKey=>$criteriaValue){
            if(is_array($criteriaValue) && 1==count($criteriaValue)){
                $criteriaValue = current($criteriaValue);
            }
            if(is_array($criteriaValue)){
                $parametersNames = array();
                $i = 0;
                foreach($criteriaValue as $v){
                    if($v instanceof \Yucca\Model\ModelAbstract) {
                        $params[":$criteriaKey$i"] = $v->getId();
                        $parametersNames[] = ":$criteriaKey$i";
                        $i++;
                    } elseif(is_scalar($v)) {
                        $params[":$criteriaKey$i"] = $v;
                        $parametersNames[] = ":$criteriaKey$i";
                        $i++;
                    } else {
                        throw new \Exception("Don't know what to do with criteria $criteriaKey");
                    }
                }
                $whereCriterias[] = "`$criteriaKey` IN (".implode(',',$parametersNames).")";
            } else {
                if($criteriaValue instanceof \Yucca\Model\ModelAbstract) {
                    $whereCriterias[] = "`$criteriaKey`=:$criteriaKey";
                    $params[":$criteriaKey"] = $criteriaValue->getId();
                } elseif(is_scalar($criteriaValue)) {
                    $whereCriterias[] = "`$criteriaKey`=:$criteriaKey";
                    $params[":$criteriaKey"] = $criteriaValue;
                } elseif($criteriaValue instanceof \Yucca\Component\Selector\Expression) {
                    $whereCriterias[] = $criteriaValue->toString('database');
                } else {
                    throw new \Exception("Don't know what to do with criteria $criteriaKey");
                }
            }
        }
        if(false === empty($whereCriterias)) {
            $sql .= ' WHERE '.implode(" AND ",$whereCriterias);
        }

        if(isset($option['orderBy'])) {
            $sql .= ' ORDER BY '.$option['orderBy'];
        }

        if(isset($option['limit'])) {
            $sql .= ' LIMIT '.$option['limit'];
        }

        return $connection->fetchAll($sql, $params);
    }

    /**
     * @param $tableName
     * @param array $criterias
     * @return int
     */
    public function remove($tableName, array $criterias) {
        $shardingKey = null;
        if(isset($criterias['sharding_key'])) {
            $shardingKey = $criterias['sharding_key'];
            unset($criterias['sharding_key']);
        }

        $connection = $this->connectionManager->getConnection(
            $this->getConnectionName($tableName, $shardingKey, true),
            true
        );

        $shardingIdentifier = $this->getShardingIdentifier($tableName,$shardingKey);
        if(isset($shardingIdentifier)) {
            $tableName = sprintf('%1$s_%2$s', $tableName, $shardingIdentifier);
        }

        $connection->delete($tableName, $criterias);

        return $this;
    }

    /**
     * Fetch one entry from database
     * @param $tableName
     * @param $identifier
     * @param bool $forceFromMaster
     * @throws \Exception
     * @return mixed
     */
    public function fetchOne($tableName, array $identifier, $forceFromMaster = true) {
        return $this->fetch($tableName, $identifier, array('*'), false, $forceFromMaster);
    }

    public function fetchIds($tableName, array $criterias, array $identifiersFields=array('id'), $forceFromMaster = false, array $options=array()) {
        return $this->fetch($tableName, $criterias, $identifiersFields, true, $forceFromMaster, $options);
    }
}
