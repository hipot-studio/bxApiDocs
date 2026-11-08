<?php

namespace Bitrix\HumanResources\Public\Service\HcmLink;

use Bitrix\HumanResources\Config\Feature;
use Bitrix\HumanResources\Contract\Repository\HcmLink\CompanyRepository;
use Bitrix\HumanResources\Item\HcmLink\Monitoring\CompanySummary;
use Bitrix\HumanResources\Item\HcmLink\Monitoring\ExchangeSummary;
use Bitrix\HumanResources\Item\HcmLink\Monitoring\StateCounters;
use Bitrix\HumanResources\Repository\HcmLink\JobMonitoringRepository;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Service\HcmLink\JobKillerService;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Type\DateTime;

/**
 * State of the exchange with the external HCM system, company by company. Read-only: it tells
 * whether 1C answers and says nothing about documents or employees.
 */
class MonitoringService
{
	/**
	 * The window of the shown counters. It matches the retention policy of JobCleaner by meaning and
	 * not by a reference to it: a job the cleaner has already removed cannot be counted anyway.
	 */
	public const SUMMARY_WINDOW = '-1 month';

	/**
	 * The window of the verdict. It also tells a fresh job from an old one and sets the threshold
	 * from which 1C is considered to have never sent the result back.
	 */
	public const VERDICT_WINDOW = '-1 day';

	private const AGENT_CANCEL_KEY = 'canceledByAgent';

	private const CACHE_TTL = 60;
	private const CACHE_ID = 'humanresources.hcmlink.monitoring.summary';

	/**
	 * The record is shared and not per user: the summary holds nothing that depends on who is
	 * looking, and the permission is checked before the cache is read.
	 */
	private const CACHE_DIR = '/humanresources/hcmlink/monitoring';

	private JobMonitoringRepository $jobMonitoringRepository;
	private CompanyRepository $companyRepository;

	public function __construct(
		?JobMonitoringRepository $jobMonitoringRepository = null,
		?CompanyRepository $companyRepository = null,
	)
	{
		$this->jobMonitoringRepository =
			$jobMonitoringRepository ?? Container::getHcmLinkJobMonitoringRepository();
		$this->companyRepository = $companyRepository ?? Container::getHcmLinkCompanyRepository();
	}

	public function isAvailable(): bool
	{
		if (!Feature::instance()->isHcmLinkAvailable())
		{
			return false;
		}

		return $this->companyRepository->getList(limit: 1)->count() > 0;
	}

	public function getSummary(): ExchangeSummary
	{
		return $this->toSummary($this->readCollected());
	}

	/**
	 * The cache keeps the flat rows of the aggregate and not the value object: they survive
	 * serialization with no enum and no date to restore, and an empty portal is told from a miss by
	 * the collection moment, which every record carries.
	 */
	private function readCollected(): array
	{
		$cache = Cache::createInstance();

		if ($cache->initCache(self::CACHE_TTL, self::CACHE_ID, self::CACHE_DIR))
		{
			$collected = $cache->getVars();
			if (is_array($collected) && isset($collected['collectedAt']))
			{
				return $collected;
			}
		}

		if (!$cache->startDataCache())
		{
			return $this->collect();
		}

		try
		{
			$collected = $this->collect();
			$cache->endDataCache($collected);

			return $collected;
		}
		catch (\Throwable $exception)
		{
			$cache->abortDataCache();

			throw $exception;
		}
	}

	/**
	 * The list of connected companies leads and the counters join it by company id, so a company
	 * without a single job says "no exchange over the period" and never "the exchange works".
	 *
	 * @return array{
	 *     companies: list<array{id: int, title: string}>,
	 *     counters: array<array{companyId: int, state: string, isRecent: bool, isAgentCancel: bool, count: int}>,
	 *     lastAnswerAt: array<int, int>,
	 *     lastSuccessStartedAt: array<int, int>,
	 *     lastTroubleAt: array<int, int>,
	 *     collectedAt: int,
	 * }
	 */
	private function collect(): array
	{
		$createdAfter = $this->boundary(self::SUMMARY_WINDOW);

		$aggregate = $this->jobMonitoringRepository->getCompanyAggregate(
			$createdAfter,
			$this->boundary(self::VERDICT_WINDOW),
			$this->boundary(JobKillerService::TTL),
		);

		$companies = [];
		foreach ($this->companyRepository->getList() as $company)
		{
			$companies[] = ['id' => (int)$company->id, 'title' => $company->title];
		}

		return [
			'companies' => $companies,
			'counters' => $aggregate['counters'],
			'lastAnswerAt' => array_map(
				static fn(DateTime $answeredAt) => $answeredAt->getTimestamp(),
				$aggregate['lastAnswerAt'],
			),
			'lastSuccessStartedAt' => array_map(
				static fn(DateTime $startedAt) => $startedAt->getTimestamp(),
				$aggregate['lastSuccessStartedAt'] ?? [],
			),
			'lastTroubleAt' => $this->collectTroubleMoments($aggregate),
			'collectedAt' => (new DateTime())->getTimestamp(),
		];
	}

	/**
	 * The moment of the trouble of a company: the later of the two kinds the verdict reads.
	 *
	 * Both moments are taken as they are. The cancel of the agent is dated by the last unanswered attempt
	 * that preceded it, and correcting that by the TTL of the agent looked tempting - the cancel does
	 * happen about one TTL later - but a cancel made from the mapping wizard can follow a resend at once,
	 * and the correction would then date the trouble later than the recovery that already happened. What
	 * closes the hole instead is the proof the verdict asks for: a successful job CREATED after this
	 * moment, not merely finished after it.
	 *
	 * @return array<int, int>
	 */
	private function collectTroubleMoments(array $aggregate): array
	{
		$moments = [];
		foreach ($aggregate['lastCancelAttemptAt'] ?? [] as $companyId => $attemptedAt)
		{
			$moments[(int)$companyId] = $attemptedAt->getTimestamp();
		}

		foreach ($aggregate['lastStuckAt'] ?? [] as $companyId => $stuckAt)
		{
			$companyId = (int)$companyId;
			$stuckTimestamp = $stuckAt->getTimestamp();
			if (!isset($moments[$companyId]) || $stuckTimestamp > $moments[$companyId])
			{
				$moments[$companyId] = $stuckTimestamp;
			}
		}

		return $moments;
	}

	private function toSummary(array $raw): ExchangeSummary
	{
		[$monthly, $recent] = $this->splitCounters($raw['counters']);

		$companies = [];
		foreach ($raw['companies'] as $company)
		{
			$companyId = (int)$company['id'];
			$answeredAt = $raw['lastAnswerAt'][$companyId] ?? null;
			// a cache written before these moments were collected simply has no keys here
			$succeededAt = $raw['lastSuccessStartedAt'][$companyId] ?? null;
			$troubledAt = $raw['lastTroubleAt'][$companyId] ?? null;

			$companies[] = new CompanySummary(
				companyId: $companyId,
				companyTitle: (string)$company['title'],
				lastAnswerAt: $answeredAt === null ? null : DateTime::createFromTimestamp((int)$answeredAt),
				counters: $this->toCounters($monthly[$companyId] ?? []),
				recentCounters: $this->toCounters($recent[$companyId] ?? []),
				lastTroubleAt: $troubledAt === null ? null : DateTime::createFromTimestamp((int)$troubledAt),
				lastSuccessStartedAt: $succeededAt === null
					? null
					: DateTime::createFromTimestamp((int)$succeededAt),
			);
		}

		return new ExchangeSummary($companies, DateTime::createFromTimestamp((int)$raw['collectedAt']));
	}

	/**
	 * @param array<array{companyId: int, state: string, isRecent: bool, isAgentCancel: bool, count: int}> $rows
	 *
	 * @return array{0: array<int, array<string, int>>, 1: array<int, array<string, int>>}
	 */
	private function splitCounters(array $rows): array
	{
		$monthly = [];
		$recent = [];

		foreach ($rows as $row)
		{
			$companyId = (int)$row['companyId'];

			$this->countRow($monthly[$companyId], $row);
			if ($row['isRecent'])
			{
				$this->countRow($recent[$companyId], $row);
			}
		}

		return [$monthly, $recent];
	}

	/**
	 * @param array<string, int>|null $countByState
	 * @param array{state: string, isAgentCancel: bool, count: int} $row
	 */
	private function countRow(?array &$countByState, array $row): void
	{
		$countByState ??= [];

		$state = (string)$row['state'];
		$count = (int)$row['count'];

		$countByState[$state] = ($countByState[$state] ?? 0) + $count;
		if ($row['isAgentCancel'])
		{
			$countByState[self::AGENT_CANCEL_KEY] = ($countByState[self::AGENT_CANCEL_KEY] ?? 0) + $count;
		}
	}

	/**
	 * @param array<string, int> $countByState
	 */
	private function toCounters(array $countByState): StateCounters
	{
		return new StateCounters(
			waiting: $countByState[JobMonitoringRepository::STATE_WAITING] ?? 0,
			notPickedUp: $countByState[JobMonitoringRepository::STATE_NOT_PICKED_UP] ?? 0,
			inProgress: $countByState[JobMonitoringRepository::STATE_IN_PROGRESS] ?? 0,
			unfinished: $countByState[JobMonitoringRepository::STATE_UNFINISHED] ?? 0,
			canceled: $countByState[JobMonitoringRepository::STATE_CANCELED] ?? 0,
			done: $countByState[JobMonitoringRepository::STATE_DONE] ?? 0,
			canceledByAgent: $countByState[self::AGENT_CANCEL_KEY] ?? 0,
		);
	}

	private function boundary(string $interval): DateTime
	{
		return (new DateTime())->add($interval);
	}
}
