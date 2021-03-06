<?php
namespace Yucca\Component\SourceFactory;

use \Yucca\Component\Source\Memcache;
use \Yucca\Component\ConnectionManager;
use \Yucca\Component\Source\DataParser\DataParser;

class MemcacheFactory implements SourceFactoryInterface
{
    /**
     * @var \Yucca\Component\ConnectionManager
     */
    protected $connectionManager;

    /**
     * @var \Yucca\Component\Source\DataParser\DataParser
     */
    protected $dataParser;

    /**
     * @param \Yucca\Component\ConnectionManager $connectionManager
     * @param \Yucca\Component\Source\DataParser\DataParser $dataParser
     */
    public function __construct(ConnectionManager $connectionManager, DataParser $dataParser) {
        $this->connectionManager = $connectionManager;
        $this->dataParser = $dataParser;
    }

    /**
     * Build source
     * @param $sourceName
     * @param array $params
     * @return \Yucca\Component\Source\Memcache
     */
    public function getSource($sourceName, array $params = array()) {
        $toReturn = new Memcache($sourceName, $params);
        $toReturn->setConnectionManager($this->connectionManager);
        $toReturn->setDataParser($this->dataParser);

        return $toReturn;
    }
}
