<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Agent;

use Bitrix\Disk\Internal\Service\Audit\AuditRetentionService;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use Throwable;

class AuditRetentionAgent extends BaseDiskAgent
{
	protected const RUN_TIME_LIMIT_SECONDS = 30;
	protected const AGENT_MAX_INTERVAL = 3600;
	protected const AGENT_MIN_INTERVAL = 900;

	private AuditRetentionService $retentionService;
	private LoggerInterface $logger;

	public function __construct(?AuditRetentionService $retentionService = null, ?LoggerInterface $logger = null)
	{
		$this->retentionService = $retentionService ?? new AuditRetentionService();
		$this->logger = $logger ?? LoggerFactory::create('audit', feature: 'retention agent');
	}

	protected function runInternal(): RunResult
	{
		try
		{
			$deadline = $this->now() + static::RUN_TIME_LIMIT_SECONDS;
			$hasMore = false;
			do
			{
				$result = $this->retentionService->deleteExpired();
				if (!$result->isSuccess())
				{
					$this->logger->error('Audit retention failed: {errors}', [
						'errors' => implode('; ', $result->getErrorMessages()),
					]);

					return new RunResult(true);
				}

				$hasMore = (bool)($result->getData()['hasMore'] ?? false);
			}
			while ($hasMore && $this->now() < $deadline);

			$this->syncAgentInterval($hasMore);
		}
		catch (Throwable $exception)
		{
			$this->logger->error('Audit retention failed: {message}', [
				'message' => $exception->getMessage(),
				'exception' => $exception,
			]);
		}

		return new RunResult(true);
	}

	protected function now(): float
	{
		return microtime(true);
	}

	protected function syncAgentInterval(bool $hasMore): void
	{
		$interval = $hasMore ? static::AGENT_MIN_INTERVAL : static::AGENT_MAX_INTERVAL;
		$agent = $this->getAgentRecord();
		if ($agent === null || (int)$agent['AGENT_INTERVAL'] === $interval)
		{
			return;
		}

		$this->writeAgentInterval((int)$agent['ID'], $interval);
	}

	protected function getAgentRecord(): ?array
	{
		$agent = \CAgent::getList(['ID' => 'DESC'], [
			'MODULE_ID' => static::$moduleId,
			'=NAME' => self::class . '::run();',
		])->fetch();

		return $agent ?: null;
	}

	protected function writeAgentInterval(int $agentId, int $interval): void
	{
		\CAgent::update($agentId, ['AGENT_INTERVAL' => $interval]);
	}
}
