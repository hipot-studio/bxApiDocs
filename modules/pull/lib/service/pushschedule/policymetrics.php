<?php

declare(strict_types=1);

namespace Bitrix\Pull\Service\PushSchedule;

use Bitrix\Main\Diag\LoggerFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class PolicyMetrics
{
	public const PROVIDER_POLICY = 'policy';
	public const PROVIDER_TIMEMAN = 'timeman';
	public const PROVIDER_INTRANET = 'intranet';

	private const LOGGER_ID = 'pull.push_schedule.policy';
	private const DECISIONS = [
		DeliveryPolicyEvaluator::DELIVER,
		DeliveryPolicyEvaluator::SUPPRESS,
	];
	private const PROVIDERS = [
		self::PROVIDER_POLICY,
		self::PROVIDER_TIMEMAN,
		self::PROVIDER_INTRANET,
	];

	private LoggerInterface $logger;
	private array $candidateCounts = [];
	private array $decisionCounts = [];
	private array $providerErrorCounts = [];
	private int $unknownSourceCount = 0;
	private int $unknownDestinationCount = 0;
	private int $invalidTimeZoneCount = 0;
	private array $durationMicroseconds = [];
	private array $batchSize = [];

	public function __construct(?LoggerInterface $logger = null)
	{
		$this->logger = $logger ?? $this->createLogger();
		$this->reset();
	}

	public function recordEvaluation(
		string $candidate,
		string $decision,
		bool $unknownSource,
		bool $unknownDestination,
		bool $invalidTimeZone,
		int $durationMicroseconds,
	): void
	{
		if (in_array($candidate, self::DECISIONS, true))
		{
			$this->candidateCounts[$candidate]++;
		}
		if (in_array($decision, self::DECISIONS, true))
		{
			$this->decisionCounts[$decision]++;
		}

		$this->unknownSourceCount += (int)$unknownSource;
		$this->unknownDestinationCount += (int)$unknownDestination;
		$this->invalidTimeZoneCount += (int)$invalidTimeZone;
		$this->recordDistribution($this->durationMicroseconds, max(0, $durationMicroseconds));
	}

	public function recordProviderError(string $provider): void
	{
		if (in_array($provider, self::PROVIDERS, true))
		{
			$this->providerErrorCounts[$provider]++;
		}
	}

	public function recordBatchSize(int $batchSize): void
	{
		$this->recordDistribution($this->batchSize, max(0, $batchSize));
	}

	public function flush(): void
	{
		try
		{
			$this->logger->info('Push schedule policy observation', $this->buildSnapshot());
		}
		catch (\Throwable)
		{
		}
		finally
		{
			$this->reset();
		}
	}

	private function createLogger(): LoggerInterface
	{
		try
		{
			return (new LoggerFactory())->createById(self::LOGGER_ID) ?? new NullLogger();
		}
		catch (\Throwable)
		{
			return new NullLogger();
		}
	}

	private function reset(): void
	{
		$this->candidateCounts = $this->emptyDecisionCounts();
		$this->decisionCounts = $this->emptyDecisionCounts();
		$this->providerErrorCounts = array_fill_keys(self::PROVIDERS, 0);
		$this->unknownSourceCount = 0;
		$this->unknownDestinationCount = 0;
		$this->invalidTimeZoneCount = 0;
		$this->durationMicroseconds = $this->emptyDistribution();
		$this->batchSize = $this->emptyDistribution();
	}

	private function emptyDecisionCounts(): array
	{
		return array_fill_keys(self::DECISIONS, 0);
	}

	private function emptyDistribution(): array
	{
		return [
			'count' => 0,
			'total' => 0,
			'max' => 0,
		];
	}

	private function recordDistribution(array &$distribution, int $value): void
	{
		$distribution['count']++;
		$distribution['total'] += $value;
		$distribution['max'] = max($distribution['max'], $value);
	}

	private function buildSnapshot(): array
	{
		return [
			'candidate' => $this->candidateCounts,
			'decision' => $this->decisionCounts,
			'unknownSource' => $this->unknownSourceCount,
			'unknownDestination' => $this->unknownDestinationCount,
			'providerError' => $this->providerErrorCounts,
			'invalidTimeZone' => $this->invalidTimeZoneCount,
			'durationMicroseconds' => $this->durationMicroseconds,
			'batchSize' => $this->batchSize,
		];
	}
}
