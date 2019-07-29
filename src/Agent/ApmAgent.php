<?php

namespace PhilKra\ElasticApmLaravel\Agent;

use Illuminate\Http\Request;
use PhilKra\Agent;
use PhilKra\Traces\Error;
use PhilKra\Traces\Span;
use PhilKra\Traces\Stacktrace;
use PhilKra\Traces\Transaction;

class ApmAgent
{
    /**
     * @var string
     */
    private $appName;

    /** @var string */
    private $appVersion;
    /**
     * @var string
     */
    private $token;
    /**
     * @var string
     */
    private $serverUrl;

    /** @var Transaction */
    private $transaction;

    /** @var Agent */
    private $agent;

    /** @var Span[] */
    private $spans = [];

    /** @var string */
    private $transactionId = '';

    public const MAX_DEBUG_TRACE = 10;

    /**
     * ApmAgent constructor.
     * @param array $context
     */
    public function __construct(array $context = [])
    {
        $this->appName = config('elastic-apm.app.appName');
        $this->token = config('elastic-apm.server.secretToken');
        $this->serverUrl = config('elastic-apm.server.serverUrl');
        $this->appVersion = config('elastic-apm.app.appVersion');
        $config = [
            'name' => $this->appName,
            'version' => $this->appVersion,
            'secretToken' => $this->token,
            'agentName' => config('elastic-apm.app.agentName'),
            'agentVersion' => config('elastic-apm.app.agentVersion'),
            'active' => config('elastic-apm.active'),
            'transport' => [
                'host' => $this->serverUrl,
                'config' => [
                    'base_uri' => $this->serverUrl,
                ],
                //'method' => 'lms',
                //'class' => Transport::class
            ],
            'framework' => [
                'name' => config('elastic-apm.framework.name'),
                'version' => config('elastic-apm.framework.version'),
            ],

        ];

        if(empty($context)) {
            $context = [
                'user'   => [],
                'custom' => [],
                'tags'   => []
            ];
        }

        $this->agent = new Agent($config, $context);
    }

    /**
     * @param Agent $agent
     */
    public function setAgent(Agent $agent) {
        $this->agent = $agent;
    }

    /**
     * @return string
     */
    public function getAppName(): string
    {
        return $this->appName;
    }

    /**
     * @return string
     */
    public function getToken(): string
    {
        return $this->token;
    }

    /**
     * @return string
     */
    public function getServerUrl(): string
    {
        return $this->serverUrl;
    }

    /**
     * Start new transaction
     * Let set open trace ID if we use Open Tracing Interface. @see: https://www.elastic.co/blog/distributed-tracing-opentracing-and-elastic-apm
     *
     *
     * @param string $transactionName
     * @param string $type
     * @param string $openTraceId
     * @return Transaction
     */
    public function startTransaction(string $transactionName, string $type, string $openTraceId = ''): Transaction {
        $this->transaction = $this->agent->factory()->newTransaction($transactionName, $type);
        $this->transaction->setTraceId(empty($openTraceId) ? $this->transaction->getId() : $openTraceId);
        if (!empty($this->transactionId)) {
            $this->transaction->setId($this->transactionId);
        }
        $this->transaction->start();

        return $this->transaction;
    }

    /**
     * Allow set custom transaction ID
     *
     * @param string $transactionId
     */
    public function setTransactionId(string $transactionId) {
        //If transaction already start
        if (!empty($this->transaction)) {
            $this->transaction->setId($transactionId);
        } else {
            //Let keep this transaction ID until it start
            $this->transactionId = $transactionId;
        }
    }

    /**
     * @return string
     */
    public function getTransactionId(): string
    {
        if (!empty($this->transaction)) {
            return $this->transaction->getId();
        }

        return $this->transactionId;
    }

    /**
     * @param Transaction $transaction
     */
    public function setTransaction(Transaction $transaction) {
        $this->transaction = $transaction;
    }

    /**
     * @return Span[]
     */
    public function getSpans(): array {
        return $this->spans;
    }

    /**
     * Notify an exception or error which registered
     * Some of errors/exeptions will be ignored by config in apm.skip_exceptions
     *
     * @param \Throwable $e
     * @return Error
     */
    public function notifyException(\Throwable $e) {
        $error = $this->agent->factory()->newError($e);
        $error->setTransaction($this->transaction);
        $error->setParentId($this->transaction->getId());
        $this->agent->register($error);
    }

    /**
     * Stop current transaction and send all data to APM server
     *
     * @return bool
     */
    public function stopTransaction() {
        //Make sure all traces were stopped
        while (!empty($this->spans)) {
            $trace = array_pop($this->spans);
            $this->stopTrace($trace);
        }
        if(!empty($this->transaction)) {
            $this->transaction->stop();
            $this->agent->register($this->transaction);

            return $this->agent->send();
        }
    }

    /**
     * Start a trace in specified feature with separated name and type
     * We push it to a parent stack in order to link the children traces to it's parent
     *
     *
     * @param string $name
     * @param string $type
     * @return Span
     */
    public function startTrace(string $name, string $type): Span {
        $span = $this->agent->factory()->newSpan($name, $type);
        $span->setTransaction($this->transaction);
        $span->setParentId($this->transaction->getId());
        $span->start();
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
     * @param Span $span
     */
    public function stopTrace(Span $span) {
        array_pop($this->spans);
        $span->stop();
        $this->agent->register($span);
    }

    /**
     * Add a Span to the Transaction
     *
     * @param Span $span
     */
    public function addSpan(Span $span) : void
    {
        $this->spans[] = $span;
    }

    /**
     * Get active transaction
     *
     * @return Transaction
     */
    public function getCurrentTransaction(): Transaction {
        return $this->transaction;
    }
}
