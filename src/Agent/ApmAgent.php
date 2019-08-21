<?php

namespace PhilKra\ElasticApmLaravel\Agent;

use Illuminate\Http\Request;
use PhilKra\Agent;
use PhilKra\Exception\InvalidConfigException;
use PhilKra\Exception\Timer\AlreadyRunningException;
use PhilKra\Exception\Timer\NotStartedException;
use PhilKra\Traces\Context;
use PhilKra\Traces\Error;
use PhilKra\Traces\Span;
use PhilKra\Traces\Stacktrace;
use PhilKra\Traces\Transaction;
use Throwable;

/**
 * Class ApmAgent
 *
 * @package App\Library
 */
class ApmAgent
{

    /**
     * Constant for MAX Debug Trace
     */
    public const MAX_DEBUG_TRACE = 10;

    /**
     * App Name
     *
     * @var string
     */
    private $appName;

    /**
     * App Version
     *
     * @var string
     */
    private $appVersion;

    /**
     * APM Token
     *
     * @var string
     */
    private $token;

    /**
     * APM Server URL
     *
     * @var string
     */
    private $serverUrl;

    /**
     * Transaction Instance
     *
     * @var Transaction
     */
    private $transaction;

    /**
     * Agent
     *
     * @var Agent
     */
    private $agent;

    /**
     * Span Collection
     *
     * @var Span[]
     */
    private $spans = [];

    /**
     * Transaction ID
     *
     * @var string
     */
    private $transactionId = '';

    /**
     * ApmAgent constructor
     *
     * @param array $context Context
     *
     * @throws InvalidConfigException
     * @throws AlreadyRunningException
     * @throws NotStartedException
     */
    public function __construct(array $context = [])
    {
        $this->appName = config('elastic-apm.app.name');
        $this->token = config('elastic-apm.server.secretToken');
        $this->serverUrl = config('elastic-apm.server.serverUrl');
        $this->appVersion = config('elastic-apm.app.version');
        $config = [
            'name' => $this->appName,
            'version' => $this->appVersion,
            'secretToken' => $this->token,
            'active' => config('elastic-apm.active'),
            'agentName' => config('elastic-apm.agent.name'),
            'environment' => config('elastic-apm.app.environment'),
            'agentVersion' => config('elastic-apm.agent.version'),
            'transport' => [
                'host' => $this->serverUrl,
                'config' => [
                    'base_uri' => $this->serverUrl,
                ],
                'timeout' => config('elastic-apm.agent.requestTimeout'),
            ],
            'framework' => [
                'name' => config('elastic-apm.framework.name'),
                'version' => config('elastic-apm.framework.version'),
            ],
            'minimumSpanDuration' => config('elastic-apm.minimumSpanDuration'),
            'maximumTransactionSpan' => config('elastic-apm.maximumTransactionSpan'),
            'sampleRate' => config('elastic-apm.sampleRate'),
        ];
        if (empty($context)) {
            $context = [
                'user'   => [],
                'custom' => [],
                'tags'   => []
            ];
        }
        $this->agent = new Agent($config, $context);
    }

    /**
     * Returns App Name
     *
     * @return string
     */
    public function getAppName(): string
    {
        return $this->appName;
    }

    /**
     * Returns APM Token
     *
     * @return string|null
     */
    public function getToken()
    {
        return $this->token;
    }

    /**
     * Returns Server URL
     *
     * @return string
     */
    public function getServerUrl(): string
    {
        return $this->serverUrl;
    }

    /**
     * Start new transaction
     *
     * @param string $transactionName Transaction Name
     * @param string $type            Transaction Type
     * @param string $openTraceId     Open Trace ID
     *
     * @throws AlreadyRunningException
     *
     * @return Transaction
     */
    public function startTransaction(string $transactionName, string $type, string $openTraceId = ''): Transaction
    {
        $this->transaction = $this->agent->factory()->newTransaction($transactionName, $type);
        $this->transaction->setTraceId(empty($openTraceId) ? $this->transaction->getTraceId() : $openTraceId);
        if (!empty($this->transactionId)) {
            $this->transaction->setId($this->transactionId);
        }
        $this->transaction->start();
        return $this->transaction;
    }

    /**
     * Allow set custom transaction ID
     *
     * @param string $transactionId Transaction ID
     *
     * @return void
     */
    public function setTransactionId(string $transactionId)
    {
        if (null !== $this->transaction) {
            $this->transaction->setId($transactionId);
        }

        $this->transactionId = $transactionId;
    }

    /**
     * Get Transaction ID
     *
     * @return string
     */
    public function getTransactionId(): string
    {
        if (null !== $this->transaction) {
            return $this->transaction->getId();
        }

        return $this->transactionId;
    }

    /**
     * Set Transaction
     *
     * @param Transaction $transaction Transaction Object
     *
     * @return void
     */
    public function setTransaction(Transaction $transaction): void
    {
        $this->transaction = $transaction;
    }

    /**
     * Get Span Collection
     *
     * @return Span[]
     */
    public function getSpans(): array
    {
        return $this->spans;
    }

    /**
     * Notify an exception or error which registered
     * Some of errors/exceptions will be ignored by config in apm.skip_exceptions
     *
     * @param Throwable $exception Exception Object
     *
     * @throws NotStartedException
     *
     * @return void
     */
    public function notifyException(Throwable $exception): void
    {
        if (null !== $this->transaction) {
            $error = $this->agent->factory()->newError($exception);
            $error->setTransaction($this->transaction);
            $error->setParentId($this->transaction->getId());
            $this->agent->register($error);
        }
    }

    /**
     * Stop current transaction and send all data to APM server
     *
     * @param string|null  $result  Transaction Result
     * @param Context|null $context Context Object
     *
     * @throws NotStartedException
     *
     * @return void
     */
    public function stopTransaction(?string $result = null, ?Context $context = null): void
    {
        while (!empty($this->spans)) {
            $trace = array_pop($this->spans);
            $this->stopTrace($trace);
        }
        if (null !== $this->transaction) {
            $this->transaction->stop($result);
            $this->transaction->setContext($context);
            $this->agent->register($this->transaction);

            $this->transaction = null;
            $this->agent->send();
        }
    }

    /**
     * Start a trace in specified feature with separated name and type
     * We push it to a parent stack in order to link the children traces to it's parent
     *
     * @param string     $name      Trace Name
     * @param string     $type      Trace Type
     * @param float|null $startTime Start Time
     * @param string     $action    Action
     *
     * @throws NotStartedException
     *
     * @return Span
     */
    public function startTrace(string $name, string $type, ?float $startTime = null, string $action = null): Span
    {
        $span = $this->agent->factory()->newSpan($name, $type, $action);
        $span->setTransaction($this->transaction);
        $span->setParentId($this->transaction->getId());
        $span->start($startTime);
        if (!empty($this->spans)) {
            $parentSpan = $this->spans[count($this->spans) - 1];
            $span->setParentId($parentSpan->getId());
        }
        $traces = Error::mapStacktrace(debug_backtrace(0, self::MAX_DEBUG_TRACE));
        unset($traces[0]);
        foreach ($traces as $trace) {
            $span->addStacktrace(new Stacktrace($trace));
        }
        array_push($this->spans, $span);
        return $span;
    }

    /**
     * Stop current trace and remove it from parent stack
     *
     * @param Span $span Span Object
     *
     * @throws NotStartedException
     *
     * @return void
     */
    public function stopTrace(Span $span): void
    {
        array_pop($this->spans);
        $span->stop();
        $this->agent->register($span);
    }

    /**
     * Get active transaction
     *
     * @return Transaction
     */
    public function getTransaction(): ?Transaction
    {
        return $this->transaction;
    }

}
