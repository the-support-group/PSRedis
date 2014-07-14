<?php

namespace PSRedis;

use PSRedis\Client\Adapter\Predis\Mock\MockedPredisClientCreatorWithNoMasterAddress;
use PSRedis\Client\BackoffStrategy\Incremental;
use PSRedis\Exception\ConnectionError;
use PSRedis\Client\Adapter\PredisClientAdapter;

class MasterDiscoveryTest extends \PHPUnit_Framework_TestCase
{
    private $monitorSetName = 'name-of-monitor-set';

    private $onlineSentinelIpAddress = '127.0.0.1';
    private $onlineSentinelPort = 2424;

    private $onlineMasterIpAddress = '198.100.10.1';
    private $onlineMasterPort = 5050;

    private $onlineSteppingDownMasterIpAddress = '198.100.10.1';
    private $onlineSteppingDownMasterPort = 5050;

    private $offlineSentinelIpAddress = '127.0.0.1';
    private $offlineSentinelPort = 2323;

    private $observedBackoff = false;

    /**
     * @return \Redis\Client
     */
    private function mockOnlineSentinel()
    {
        $clientAdapter = new PredisClientAdapter(new MockedPredisClientCreatorWithNoMasterAddress(), Client::TYPE_SENTINEL);

        $redisClient = \Phake::mock('\\PSRedis\Client');
        \Phake::when($redisClient)->getIpAddress()->thenReturn($this->onlineMasterIpAddress);
        \Phake::when($redisClient)->getPort()->thenReturn($this->onlineMasterPort);
        \Phake::when($redisClient)->isMaster()->thenReturn(true);
        \Phake::when($redisClient)->getRole()->thenReturn(Client::ROLE_MASTER);

        $sentinelClient = \Phake::mock('\\PSRedis\\Client');
        \Phake::when($sentinelClient)->connect()->thenReturn(null);
        \Phake::when($sentinelClient)->getIpAddress()->thenReturn($this->onlineSentinelIpAddress);
        \Phake::when($sentinelClient)->getPort()->thenReturn($this->onlineSentinelPort);
        \Phake::when($sentinelClient)->getClientAdapter()->thenReturn($clientAdapter);
        \Phake::when($sentinelClient)->getMaster(\Phake::anyParameters())->thenReturn($redisClient);

        return $sentinelClient;
    }

    /**
     * @return \Redis\Client
     */
    private function mockOfflineSentinel()
    {
        $sentinelClient = \Phake::mock('\\PSRedis\\Client');
        \Phake::when($sentinelClient)->connect()->thenThrow(
            new ConnectionError(sprintf('Could not connect to sentinel at %s:%d', $this->offlineSentinelIpAddress, $this->offlineSentinelPort))
        );
        \Phake::when($sentinelClient)->getIpAddress()->thenReturn($this->offlineSentinelIpAddress);
        \Phake::when($sentinelClient)->getPort()->thenReturn($this->offlineSentinelPort);

        return $sentinelClient;
    }

    private function mockOnlineSentinelWithMasterSteppingDown()
    {
        $clientAdapter = new PredisClientAdapter(new MockedPredisClientCreatorWithNoMasterAddress(), Client::TYPE_SENTINEL);

        $masterNodeSteppingDown = \Phake::mock('\\PSRedis\Client');
        \Phake::when($masterNodeSteppingDown)->getIpAddress()->thenReturn($this->onlineSteppingDownMasterIpAddress);
        \Phake::when($masterNodeSteppingDown)->getPort()->thenReturn($this->onlineSteppingDownMasterPort);
        \Phake::when($masterNodeSteppingDown)->isMaster()->thenReturn(false);

        $masterNode = \Phake::mock('\\PSRedis\Client');
        \Phake::when($masterNode)->getIpAddress()->thenReturn($this->onlineMasterIpAddress);
        \Phake::when($masterNode)->getPort()->thenReturn($this->onlineMasterPort);
        \Phake::when($masterNode)->isMaster()->thenReturn(true);

        $sentinelClient = \Phake::mock('\\PSRedis\\Client');
        \Phake::when($sentinelClient)->connect()->thenReturn(null);
        \Phake::when($sentinelClient)->getIpAddress()->thenReturn($this->onlineSentinelIpAddress);
        \Phake::when($sentinelClient)->getPort()->thenReturn($this->onlineSentinelPort);
        \Phake::when($sentinelClient)->getClientAdapter()->thenReturn($clientAdapter);
        \Phake::when($sentinelClient)->getMaster(\Phake::anyParameters())
            ->thenReturn($masterNodeSteppingDown)
            ->thenReturn($masterNode);

        return $sentinelClient;
    }

    public function testAMonitorSetHasAName()
    {
        $monitorSet = new MasterDiscovery($this->monitorSetName);
        $this->assertEquals($this->monitorSetName, $monitorSet->getName(), 'A monitor set is identified by a name');
    }

    public function testAMonitorSetNameCannotBeEmpty()
    {
        $this->setExpectedException('\\PSRedis\\Exception\\InvalidProperty', 'A monitor set needs a valid name');
        new MasterDiscovery('');
    }

    public function testThatSentinelClientsCanBeAddedToMonitorSets()
    {
        $monitorSet = new MasterDiscovery($this->monitorSetName);
        $monitorSet->addSentinel($this->mockOnlineSentinel());
        $this->assertAttributeCount(1, 'sentinels', $monitorSet, 'Sentinel node can be added to a monitor set');
    }

    public function testThatOnlySentinelClientObjectsCanBeAddedAsNode()
    {
        $this->setExpectedException('\\PHPUnit_Framework_Error', 'Argument 1 passed to PSRedis\MasterDiscovery::addSentinel() must be an instance of PSRedis\Client');
        $monitorSet = new MasterDiscovery($this->monitorSetName);
        $monitorSet->addSentinel(new \StdClass());
    }

    public function testThatWeNeedNodesConfigurationToDiscoverAMaster()
    {
        $this->setExpectedException('\\PSRedis\\Exception\\ConfigurationError', 'You need to configure and add sentinel nodes before attempting to fetch a master');
        $monitorSet = new MasterDiscovery($this->monitorSetName);
        $monitorSet->getMaster();
    }

    public function testThatMasterCannotBeFoundIfWeCannotConnectToSentinels()
    {
        $this->setExpectedException('\\PSRedis\\Exception\\ConnectionError', 'All sentinels are unreachable');
        $sentinel1 = $this->mockOfflineSentinel();
        $sentinel2 = $this->mockOfflineSentinel();
        $monitorSet = new MasterDiscovery('all-fail');
        $monitorSet->addSentinel($sentinel1);
        $monitorSet->addSentinel($sentinel2);
        $monitorSet->getMaster();
    }

    public function testThatSentinelNodeIsReturnedOnSuccessfulMasterDiscovery()
    {
        $noBackoff = new Incremental(0, 1);
        $noBackoff->setMaxAttempts(1);

        $sentinel1 = $this->mockOfflineSentinel();
        $sentinel2 = $this->mockOnlineSentinel();

        $monitorSet = new MasterDiscovery('online-sentinel');
        $monitorSet->setBackoffStrategy($noBackoff);
        $monitorSet->addSentinel($sentinel1);
        $monitorSet->addSentinel($sentinel2);
        $masterNode = $monitorSet->getMaster();

        $this->assertInstanceOf('\\PSRedis\\Client', $masterNode, 'The master returned should be an instance of \\PSRedis\\Client');
        $this->assertEquals($this->onlineMasterIpAddress, $masterNode->getIpAddress(), 'The master node IP address returned should be the one of the online sentinel');
        $this->assertEquals($this->onlineMasterPort, $masterNode->getPort(), 'The master node IP port returned should be the one of the online sentinel');
    }

    public function testThatMasterStatusOfANodeIsCheckedAfterConnecting()
    {
        $this->setExpectedException('\\PSRedis\\Exception\\ConnectionError', 'All sentinels are unreachable');

        $sentinel1 = $this->mockOnlineSentinelWithMasterSteppingDown();
        $sentinel2 = $this->mockOnlineSentinel();
        $monitorSet = new MasterDiscovery('online-sentinel');
        $monitorSet->addSentinel($sentinel1);
        $monitorSet->addSentinel($sentinel2);
        $monitorSet->getMaster();
    }

    public function testThatABackoffIsAttempted()
    {
        $backoffOnce = new Incremental(0, 1);
        $backoffOnce->setMaxAttempts(2);

        $sentinel1 = $this->mockOfflineSentinel();
        $sentinel2 = $this->mockOnlineSentinelWithMasterSteppingDown();

        $monitorSet = new MasterDiscovery('online-sentinel');
        $monitorSet->setBackoffStrategy($backoffOnce);
        $monitorSet->addSentinel($sentinel1);
        $monitorSet->addSentinel($sentinel2);
        $masterNode = $monitorSet->getMaster();

        $this->assertEquals($this->onlineMasterIpAddress, $masterNode->getIpAddress(), 'A master that stepped down between discovery and connecting should be retried after backoff (check IP address)');
        $this->assertEquals($this->onlineMasterPort, $masterNode->getPort(), 'A master that stepped down between discovery and connecting should be retried after backoff (check port)');
    }

    public function testThatTheMasterHasTheCorrectRole()
    {
        $noBackoff = new Incremental(0, 1);
        $noBackoff->setMaxAttempts(1);

        $sentinel1 = $this->mockOfflineSentinel();
        $sentinel2 = $this->mockOnlineSentinel();

        $monitorSet = new MasterDiscovery('online-sentinel');
        $monitorSet->setBackoffStrategy($noBackoff);
        $monitorSet->addSentinel($sentinel1);
        $monitorSet->addSentinel($sentinel2);
        $masterNode = $monitorSet->getMaster();

        $this->assertEquals(Client::ROLE_MASTER, $masterNode->getRole(), 'The role of the master should be \'master\'');
    }

    public function testThatAnObserverIsCalledOnBackoff()
    {
        $this->observedBackoff = false;

        $backoffOnce = new Incremental(0, 1);
        $backoffOnce->setMaxAttempts(2);

        $sentinel1 = $this->mockOfflineSentinel();
        $sentinel2 = $this->mockOnlineSentinelWithMasterSteppingDown();

        $monitorSet = new MasterDiscovery('online-sentinel');
        $monitorSet->setBackoffStrategy($backoffOnce);
        $monitorSet->addSentinel($sentinel1);
        $monitorSet->addSentinel($sentinel2);
        $monitorSet->setBackoffObserver(array($this, 'backoffObserver'));
        $monitorSet->getMaster();

        $this->assertTrue($this->observedBackoff, 'When backing off an observer can be called');
    }

    public function backoffObserver()
    {
        $this->observedBackoff = true;
    }
}
 