<?php

namespace Bitrix\BIConnector\Rest;

use Bitrix\BIConnector\Integration\Superset\Integrator\Dto\SqlExecutionContext;
use Bitrix\BIConnector\Integration\Superset\Integrator\Dto\User;
use Bitrix\BIConnector\Internal\Feature\SqlLabRestGate;
use Bitrix\BIConnector\Internal\Feature\SupersetPlacementResolver;
use Bitrix\BIConnector\Internal\Services\SqlLab\SqlLabClock;
use Bitrix\BIConnector\Internal\Services\SqlLab\SqlLabExecutionService;
use Bitrix\BIConnector\Internal\Services\SqlLab\SqlLabRestLease;
use Bitrix\BIConnector\Internal\Services\SqlLab\SqlLabRestLimiter;
use Bitrix\BIConnector\Superset\Logger\SqlLabRestLogger;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class SqlLab
{
	public const SCOPE = 'biconnector';
	public const METHOD = 'biconnector.sqllab.execute';

	private const DEFAULT_LIMIT = 1000;
	private const MAX_LIMIT = 10000;

	private readonly SqlLabCallContextResolver $contextResolver;
	private readonly SqlLabRestGate $gate;
	private readonly SqlLabRestLimiter $limiter;
	private readonly SqlLabExecutionService $executionService;
	private readonly SqlLabRestLogger $logger;
	private readonly SqlLabClock $clock;
	private readonly SupersetPlacementResolver $placementResolver;

	public function __construct(
		?SqlLabCallContextResolver $contextResolver = null,
		?SqlLabRestGate $gate = null,
		?SqlLabRestLimiter $limiter = null,
		?SqlLabExecutionService $executionService = null,
		?SqlLabRestLogger $logger = null,
		?SqlLabClock $clock = null,
		?SupersetPlacementResolver $placementResolver = null,
	)
	{
		$this->contextResolver = $contextResolver ?? new SqlLabCallContextResolver();
		$this->gate = $gate ?? new SqlLabRestGate();
		$this->limiter = $limiter ?? new SqlLabRestLimiter();
		$this->executionService = $executionService ?? new SqlLabExecutionService();
		$this->logger = $logger ?? new SqlLabRestLogger();
		$this->clock = $clock ?? new SqlLabClock();
		$this->placementResolver = $placementResolver ?? new SupersetPlacementResolver();
	}

	public static function OnRestServiceBuildDescription(): array
	{
		return [
			self::SCOPE => [
				self::METHOD => [
					'callback' => [self::class, 'execute'],
					'options' => [],
				],
			],
		];
	}

	public static function execute(array $params, $n, \CRestServer $server): array
	{
		return (new self())->handle($params, $server);
	}

	public function handle(array $params, \CRestServer $server): array
	{
		$startedAt = $this->clock->microtime();
		$event = [
			'placement' => $this->resolvePlacement(),
			'outcome' => 'error',
			'rowCount' => 0,
			'limit' => $this->logLimit($params['limit'] ?? null),
			'limiterDimension' => null,
			'code' => 'SQLLAB_UNAVAILABLE',
			'source' => null,
			'correlationId' => null,
		];

		// A refusal that never reached the limiter is not covered by any quota of SQL Lab, so it
		// stays out of the audit journal: a stream of them would write to a table shared by the
		// whole portal without a bound.
		$isAuditable = false;

		try
		{
			$contextResult = $this->contextResolver->resolve($server);
			if (!$contextResult->isSuccess())
			{
				return $this->errorResponse($contextResult, $server, $event);
			}

			$context = $contextResult->getData()['context'] ?? null;
			if (!$context instanceof SqlLabCallContext)
			{
				return $this->errorResponse(null, $server, $event);
			}
			$event['userId'] = $context->user->id;
			$event['callerKey'] = $context->callerKey;

			try
			{
				$isEnabled = $this->gate->isEnabled($context->user->id);
			}
			catch (\Throwable)
			{
				$isEnabled = false;
			}
			if (!$isEnabled)
			{
				return $this->errorResponse($this->failedResult('SQLLAB_ACCESS_DENIED'), $server, $event);
			}

			$isAuditable = true;
			$limiterResult = $this->limiter->acquire($context->user->id, $context->callerKey);
			if (!$limiterResult->isSuccess())
			{
				$event['limiterDimension'] = $limiterResult->getData()['limiterDimension'] ?? null;

				return $this->errorResponse($limiterResult, $server, $event);
			}

			$lease = $limiterResult->getData()['lease'] ?? null;
			if (!$lease instanceof SqlLabRestLease)
			{
				return $this->errorResponse(null, $server, $event);
			}

			try
			{
				$result = $this->runQuery($params, $context->user);
			}
			catch (\Throwable)
			{
				$result = $this->failedResult('SQLLAB_UNAVAILABLE');
			}
			finally
			{
				$lease->release();
			}

			if (!$result->isSuccess())
			{
				return $this->errorResponse($result, $server, $event);
			}

			$data = $result->getData();
			$event['outcome'] = 'success';
			$event['code'] = 'SQLLAB_OK';
			$event['rowCount'] = is_int($data['rowCount'] ?? null) ? $data['rowCount'] : 0;
			$event['limit'] = is_int($data['limit'] ?? null) ? $data['limit'] : $event['limit'];

			return [
				'columns' => is_array($data['columns'] ?? null) ? $data['columns'] : [],
				'rows' => is_array($data['rows'] ?? null) ? $data['rows'] : [],
				'rowCount' => $event['rowCount'],
				'limit' => $event['limit'],
				'truncated' => is_bool($data['truncated'] ?? null) ? $data['truncated'] : null,
			];
		}
		catch (\Throwable)
		{
			return $this->errorResponse(null, $server, $event);
		}
		finally
		{
			if ($isAuditable)
			{
				$event['durationMs'] = max(0, (int)round(($this->clock->microtime() - $startedAt) * 1000));
				try
				{
					$this->logger->log($event);
				}
				catch (\Throwable)
				{
				}
			}
		}
	}

	/**
	 * The shared service takes typed arguments, so their form is checked here; an inactive account
	 * never gets this far, SqlLabCallContextResolver refuses it.
	 */
	private function runQuery(array $params, User $user): Result
	{
		$sql = $params['sql'] ?? null;
		$limit = $this->readLimit($params['limit'] ?? null);
		if (!is_string($sql) || $limit === null || !$this->executionService->isValidQuery($sql, $limit))
		{
			return $this->failedResult('SQLLAB_INVALID_QUERY');
		}

		return $this->executionService->execute($sql, $limit, SqlExecutionContext::forRest($user));
	}

	/**
	 * An oversized limit is refused here and not cut down: the method has always answered it
	 * with SQLLAB_INVALID_QUERY.
	 */
	private function readLimit(mixed $limit): ?int
	{
		if ($limit === null)
		{
			return self::DEFAULT_LIMIT;
		}

		if (is_int($limit))
		{
			$value = $limit;
		}
		elseif (is_string($limit) && preg_match('/^[0-9]+$/D', $limit) === 1)
		{
			$value = (int)$limit;
		}
		else
		{
			return null;
		}

		return $value >= 1 && $value <= self::MAX_LIMIT ? $value : null;
	}

	private function errorResponse(?Result $result, \CRestServer $server, array &$event): array
	{
		$errors = $result?->getErrors() ?? [new Error('SQL Lab is temporarily unavailable.', 'SQLLAB_UNAVAILABLE')];
		$mapped = (new SqlLabErrorMapper())->forDirect($errors);
		$wireCode = $this->wireCode($errors);
		$event['code'] = $wireCode;
		$event['outcome'] = match ($wireCode) {
			'SQLLAB_INVALID_QUERY' => 'invalid',
			'SQLLAB_ACCESS_DENIED' => 'denied',
			'SQLLAB_RATE_LIMIT_EXCEEDED' => 'rate_limited',
			'SQLLAB_TIMEOUT_ERROR' => 'timeout',
			default => 'error',
		};
		$server->setStatus($this->httpStatus($mapped['httpStatus']));

		return $mapped['body'];
	}

	private function failedResult(string $code): Result
	{
		$result = new Result();
		$result->addError(new Error('SQL Lab request failed.', $code));

		return $result;
	}

	private function wireCode(array $errors): string
	{
		$allowed = [
			'SQLLAB_INVALID_QUERY',
			'SQLLAB_ACCESS_DENIED',
			'SQLLAB_RATE_LIMIT_EXCEEDED',
			'SQLLAB_TIMEOUT_ERROR',
			'SQLLAB_UNAVAILABLE',
		];
		foreach ($errors as $error)
		{
			if ($error instanceof Error && in_array($error->getCode(), $allowed, true))
			{
				return $error->getCode();
			}
		}

		return 'SQLLAB_UNAVAILABLE';
	}

	private function httpStatus(int $status): string
	{
		return match ($status) {
			400 => \CRestServer::STATUS_WRONG_REQUEST,
			403 => \CRestServer::STATUS_FORBIDDEN,
			429 => \CRestServer::STATUS_TO_MANY_REQUESTS,
			504 => '504 Gateway Timeout',
			default => '503 Service Unavailable',
		};
	}

	private function resolvePlacement(): string
	{
		try
		{
			return $this->placementResolver->resolve();
		}
		catch (\Throwable)
		{
			return '';
		}
	}

	private function logLimit(mixed $limit): int
	{
		return $this->readLimit($limit) ?? 0;
	}
}
