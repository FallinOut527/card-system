<?php
 namespace Predis; use Predis\Command\CommandInterface; use Predis\Command\RawCommand; use Predis\Command\ScriptCommand; use Predis\Configuration\Options; use Predis\Configuration\OptionsInterface; use Predis\Connection\AggregateConnectionInterface; use Predis\Connection\ConnectionInterface; use Predis\Connection\ParametersInterface; use Predis\Monitor\Consumer as MonitorConsumer; use Predis\Pipeline\Pipeline; use Predis\PubSub\Consumer as PubSubConsumer; use Predis\Response\ErrorInterface as ErrorResponseInterface; use Predis\Response\ResponseInterface; use Predis\Response\ServerException; use Predis\Transaction\MultiExec as MultiExecTransaction; class Client implements ClientInterface, \IteratorAggregate { const VERSION = '1.1.1'; protected $connection; protected $options; private $profile; public function __construct($parameters = null, $options = null) { $this->options = $this->createOptions($options ?: array()); $this->connection = $this->createConnection($parameters ?: array()); $this->profile = $this->options->profile; } protected function createOptions($options) { if (is_array($options)) { return new Options($options); } if ($options instanceof OptionsInterface) { return $options; } throw new \InvalidArgumentException('Invalid type for client options.'); } protected function createConnection($parameters) { if ($parameters instanceof ConnectionInterface) { return $parameters; } if ($parameters instanceof ParametersInterface || is_string($parameters)) { return $this->options->connections->create($parameters); } if (is_array($parameters)) { if (!isset($parameters[0])) { return $this->options->connections->create($parameters); } $options = $this->options; if ($options->defined('aggregate')) { $initializer = $this->getConnectionInitializerWrapper($options->aggregate); $connection = $initializer($parameters, $options); } elseif ($options->defined('replication')) { $replication = $options->replication; if ($replication instanceof AggregateConnectionInterface) { $connection = $replication; $options->connections->aggregate($connection, $parameters); } else { $initializer = $this->getConnectionInitializerWrapper($replication); $connection = $initializer($parameters, $options); } } else { $connection = $options->cluster; $options->connections->aggregate($connection, $parameters); } return $connection; } if (is_callable($parameters)) { $initializer = $this->getConnectionInitializerWrapper($parameters); $connection = $initializer($this->options); return $connection; } throw new \InvalidArgumentException('Invalid type for connection parameters.'); } protected function getConnectionInitializerWrapper($callable) { return function () use ($callable) { $connection = call_user_func_array($callable, func_get_args()); if (!$connection instanceof ConnectionInterface) { throw new \UnexpectedValueException( 'The callable connection initializer returned an invalid type.' ); } return $connection; }; } public function getProfile() { return $this->profile; } public function getOptions() { return $this->options; } public function getClientFor($connectionID) { if (!$connection = $this->getConnectionById($connectionID)) { throw new \InvalidArgumentException("Invalid connection ID: $connectionID."); } return new static($connection, $this->options); } public function connect() { $this->connection->connect(); } public function disconnect() { $this->connection->disconnect(); } public function quit() { $this->disconnect(); } public function isConnected() { return $this->connection->isConnected(); } public function getConnection() { return $this->connection; } public function getConnectionById($connectionID) { if (!$this->connection instanceof AggregateConnectionInterface) { throw new NotSupportedException( 'Retrieving connections by ID is supported only by aggregate connections.' ); } return $this->connection->getConnectionById($connectionID); } public function executeRaw(array $arguments, &$error = null) { $error = false; $response = $this->connection->executeCommand( new RawCommand($arguments) ); if ($response instanceof ResponseInterface) { if ($response instanceof ErrorResponseInterface) { $error = true; } return (string) $response; } return $response; } public function __call($commandID, $arguments) { return $this->executeCommand( $this->createCommand($commandID, $arguments) ); } public function createCommand($commandID, $arguments = array()) { return $this->profile->createCommand($commandID, $arguments); } public function executeCommand(CommandInterface $command) { $response = $this->connection->executeCommand($command); if ($response instanceof ResponseInterface) { if ($response instanceof ErrorResponseInterface) { $response = $this->onErrorResponse($command, $response); } return $response; } return $command->parseResponse($response); } protected function onErrorResponse(CommandInterface $command, ErrorResponseInterface $response) { if ($command instanceof ScriptCommand && $response->getErrorType() === 'NOSCRIPT') { $eval = $this->createCommand('EVAL'); $eval->setRawArguments($command->getEvalArguments()); $response = $this->executeCommand($eval); if (!$response instanceof ResponseInterface) { $response = $command->parseResponse($response); } return $response; } if ($this->options->exceptions) { throw new ServerException($response->getMessage()); } return $response; } private function sharedContextFactory($initializer, $argv = null) { switch (count($argv)) { case 0: return $this->$initializer(); case 1: return is_array($argv[0]) ? $this->$initializer($argv[0]) : $this->$initializer(null, $argv[0]); case 2: list($arg0, $arg1) = $argv; return $this->$initializer($arg0, $arg1); default: return $this->$initializer($this, $argv); } } public function pipeline() { return $this->sharedContextFactory('createPipeline', func_get_args()); } protected function createPipeline(array $options = null, $callable = null) { if (isset($options['atomic']) && $options['atomic']) { $class = 'Predis\Pipeline\Atomic'; } elseif (isset($options['fire-and-forget']) && $options['fire-and-forget']) { $class = 'Predis\Pipeline\FireAndForget'; } else { $class = 'Predis\Pipeline\Pipeline'; } $pipeline = new $class($this); if (isset($callable)) { return $pipeline->execute($callable); } return $pipeline; } public function transaction() { return $this->sharedContextFactory('createTransaction', func_get_args()); } protected function createTransaction(array $options = null, $callable = null) { $transaction = new MultiExecTransaction($this, $options); if (isset($callable)) { return $transaction->execute($callable); } return $transaction; } public function pubSubLoop() { return $this->sharedContextFactory('createPubSub', func_get_args()); } protected function createPubSub(array $options = null, $callable = null) { $pubsub = new PubSubConsumer($this, $options); if (!isset($callable)) { return $pubsub; } foreach ($pubsub as $message) { if (call_user_func($callable, $pubsub, $message) === false) { $pubsub->stop(); } } } public function monitor() { return new MonitorConsumer($this); } public function getIterator() { $clients = array(); $connection = $this->getConnection(); if (!$connection instanceof \Traversable) { throw new ClientException('The underlying connection is not traversable'); } foreach ($connection as $node) { $clients[(string) $node] = new static($node, $this->getOptions()); } return new \ArrayIterator($clients); } } 