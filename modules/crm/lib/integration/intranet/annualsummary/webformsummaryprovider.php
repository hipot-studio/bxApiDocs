<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\Intranet\AnnualSummary;

use Bitrix\Crm\Service\Container;
use Bitrix\Intranet\Internal\Entity\AnnualSummary\Summary;
use Bitrix\Intranet\Public\Provider\AnnualSummary\SummaryProviderInterface;
use Bitrix\Main\Entity\EntityCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Date;
use Psr\Log\LoggerInterface;
use Throwable;

if (!Loader::includeModule('intranet'))
{
	return;
}

final class WebFormSummaryProvider implements SummaryProviderInterface
{
	public const ID = 'crm.webform.annual_summary';
	public const USER_ID_LIMIT = 100;

	private const METRICS = [
		'createdForms' => 'crm.webform.created_forms',
		'views' => 'crm.webform.views',
		'submissions' => 'crm.webform.submissions',
		'abandonedFills' => 'crm.webform.abandoned_fills',
		'averageFillTimeSeconds' => 'crm.webform.average_fill_time_seconds',
		'fullyFilled' => 'crm.webform.fully_filled',
		'sourceSite' => 'crm.webform.source.site',
		'sourceLanding' => 'crm.webform.source.landing',
		'sourceQr' => 'crm.webform.source.qr',
		'sourceEmail' => 'crm.webform.source.email',
		'sourceMessenger' => 'crm.webform.source.messenger',
		'sourceDirectLink' => 'crm.webform.source.direct_link',
		'sourceWidget' => 'crm.webform.source.widget',
		'sourceUnknown' => 'crm.webform.source.unknown',
		'deviceDesktop' => 'crm.webform.device.desktop',
		'deviceMobile' => 'crm.webform.device.mobile',
		'deviceOther' => 'crm.webform.device.other',
		'errorValidation' => 'crm.webform.error.validation',
		'errorOther' => 'crm.webform.error.other',
		'typeEvent' => 'crm.webform.type.event',
		'typeStatistics' => 'crm.webform.type.statistics',
		'typeFeedback' => 'crm.webform.type.feedback',
		'typeOther' => 'crm.webform.type.other',
	];
	private const LEGACY_METRIC_KEYS = [
		'createdForms',
		'views',
		'submissions',
		'abandonedFills',
	];

	private int $lastUserId = 0;
	private readonly MetricCalculatorInterface $calculator;
	private readonly AccessCheckerInterface $accessChecker;
	private readonly LoggerInterface $logger;
	private readonly SummaryCleanerInterface $summaryCleaner;
	private readonly MetricSnapshotCacheInterface $metricSnapshotCache;

	public function __construct(
		private readonly Date $from,
		private readonly Date $to,
		?MetricCalculatorInterface $calculator = null,
		?AccessCheckerInterface $accessChecker = null,
		?LoggerInterface $logger = null,
		?SummaryCleanerInterface $summaryCleaner = null,
		?MetricSnapshotCacheInterface $metricSnapshotCache = null,
	)
	{
		$this->calculator = $calculator ?? new WebFormMetricCalculator();
		$this->accessChecker = $accessChecker ?? new WebFormAccessChecker();
		$this->logger = $logger ?? Container::getInstance()->getLogger('AnnualSummary');
		$this->summaryCleaner = $summaryCleaner ?? new IntranetSummaryCleaner();
		$this->metricSnapshotCache = $metricSnapshotCache ?? new WebFormMetricSnapshotCache();
	}

	public function getId(): string
	{
		return self::ID;
	}

	public function getLastUserId(): int
	{
		return $this->lastUserId;
	}

	public function getUserIdLimit(): int
	{
		return self::USER_ID_LIMIT;
	}

	public function provide(array $userIds): EntityCollection
	{
		$userIds = $this->normalizeUserIds($userIds);
		$this->lastUserId = $userIds === [] ? 0 : max($userIds);

		[$allowedUserIds, $deniedUserIds] = $this->partitionUserIdsByAccess($userIds);
		$this->deleteDeniedMetrics($deniedUserIds);
		$collection = new EntityCollection();
		if ($allowedUserIds === [])
		{
			return $collection;
		}

		try
		{
			$totals = $this->metricSnapshotCache->getOrCalculate(
				$this->from,
				$this->to,
				fn(): array => $this->calculator->calculate($this->from, $this->to),
			);
		}
		catch (Throwable $error)
		{
			$this->logger->error(
				'Failed to calculate CRM web form annual summary metrics',
				[
					'exception' => $error,
					'userIds' => $allowedUserIds,
					'from' => $this->from->format('Y-m-d'),
					'to' => $this->to->format('Y-m-d'),
				],
			);

			return $collection;
		}

		[$allowedUserIds, $deniedUserIds] = $this->partitionUserIdsByAccess($allowedUserIds);
		$this->deleteDeniedMetrics($deniedUserIds);
		if ($allowedUserIds === [])
		{
			return $collection;
		}

		foreach ($allowedUserIds as $userId)
		{
			foreach (self::METRICS as $totalKey => $summaryId)
			{
				if (!in_array($totalKey, self::LEGACY_METRIC_KEYS, true) && !array_key_exists($totalKey, $totals))
				{
					continue;
				}

				$collection->add(new Summary($userId, $summaryId, (int)($totals[$totalKey] ?? 0)));
			}
		}

		return $collection;
	}

	/** @return int[] */
	private function normalizeUserIds(array $userIds): array
	{
		$normalized = [];
		foreach ($userIds as $userId)
		{
			if (!is_int($userId) && !is_string($userId) && !is_float($userId))
			{
				continue;
			}

			$userId = (int)$userId;
			if ($userId > 0)
			{
				$normalized[$userId] = $userId;
			}
		}

		return array_values($normalized);
	}

	/** @param int[] $userIds */
	private function deleteDeniedMetrics(array $userIds): void
	{
		if ($userIds !== [])
		{
			$this->summaryCleaner->delete($userIds, array_values(self::METRICS));
		}
	}

	/**
	 * @param int[] $userIds
	 * @return array{0: int[], 1: int[]}
	 */
	private function partitionUserIdsByAccess(array $userIds): array
	{
		try
		{
			$allowedUserIds = array_fill_keys($this->accessChecker->getAllowedUserIds($userIds), true);
		}
		catch (Throwable $error)
		{
			$this->logger->error(
				'Failed to check CRM web form annual summary access',
				[
					'exception' => $error,
					'userIds' => $userIds,
				],
			);
			$allowedUserIds = [];
		}

		$allowed = [];
		$denied = [];
		foreach ($userIds as $userId)
		{
			if (isset($allowedUserIds[$userId]))
			{
				$allowed[] = $userId;
			}
			else
			{
				$denied[] = $userId;
			}
		}

		return [$allowed, $denied];
	}
}
