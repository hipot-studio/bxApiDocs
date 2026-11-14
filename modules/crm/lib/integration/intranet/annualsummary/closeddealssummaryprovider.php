<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\Intranet\AnnualSummary;

use Bitrix\Crm\Service\Container;
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

final class ClosedDealsSummaryProvider implements SummaryProviderInterface
{
	public const ID = 'crm.closed_deals';
	public const USER_ID_LIMIT = 100;

	private int $lastUserId = 0;
	private readonly ClosedDealsCalculator $calculator;

	public function __construct(
		private readonly Date $from,
		private readonly Date $to,
		?ClosedDealsCalculator $calculator = null,
		private readonly ?LoggerInterface $logger = null,
	)
	{
		$this->calculator = $calculator ?? new ClosedDealsCalculator();
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
		if ($userIds === [])
		{
			$this->lastUserId = 0;

			return new EntityCollection();
		}

		$this->lastUserId = max($userIds);

		try
		{
			if (count($userIds) > self::USER_ID_LIMIT)
			{
				throw new \InvalidArgumentException('The user batch exceeds the supported limit');
			}

			$metrics = $this->calculator->calculate($this->from, $this->to, $userIds);
			$this->validateMetrics($userIds, $metrics);

			$collection = new EntityCollection();
			foreach ($userIds as $userId)
			{
				$collection->add(new ClosedDealSummary($userId, $metrics[$userId]['total']));
				$collection->add(new ClosedDealBestMonthSummary($userId, $metrics[$userId]['bestMonth']));
			}

			return $collection;
		}
		catch (Throwable $error)
		{
			$this->logBatchFailure($userIds, $error);

			return new EntityCollection();
		}
	}

	/**
	 * @return int[] unique positive IDs in ascending order
	 */
	private function normalizeUserIds(array $userIds): array
	{
		$uniqueUserIds = [];
		foreach ($userIds as $userId)
		{
			if (!is_scalar($userId))
			{
				continue;
			}

			$userId = (int)$userId;
			if ($userId > 0)
			{
				$uniqueUserIds[$userId] = $userId;
			}
		}

		$userIds = array_values($uniqueUserIds);
		sort($userIds, SORT_NUMERIC);

		return $userIds;
	}

	/**
	 * @param int[] $userIds normalized batch
	 */
	private function logBatchFailure(array $userIds, Throwable $error): void
	{
		try
		{
			($this->logger ?? Container::getInstance()->getLogger('Default'))->error(
				'Failed to calculate CRM closed deals annual summary metrics',
				[
					'from' => $this->from->format('Y-m-d'),
					'to' => $this->to->format('Y-m-d'),
					'batchSize' => count($userIds),
					'exceptionClass' => get_class($error),
					'firstUserId' => $userIds[0],
					'lastUserId' => $userIds[array_key_last($userIds)],
				],
			);
		}
		catch (Throwable)
		{
		}
	}

	/**
	 * @param int[] $userIds
	 * @param array<int, array{total: int, bestMonth: int}> $metrics
	 */
	private function validateMetrics(array $userIds, array $metrics): void
	{
		$expectedUserIds = array_fill_keys($userIds, true);
		foreach ($metrics as $userId => $metric)
		{
			if (!is_int($userId) || !isset($expectedUserIds[$userId]))
			{
				throw new \UnexpectedValueException('The calculator returned an unexpected user');
			}
			if (
				!is_array($metric)
				|| count($metric) !== 2
				|| !array_key_exists('total', $metric)
				|| !array_key_exists('bestMonth', $metric)
				|| !is_int($metric['total'])
				|| $metric['total'] < 0
				|| !is_int($metric['bestMonth'])
			)
			{
				throw new \UnexpectedValueException('The calculator returned an invalid metric pair');
			}
			if (
				($metric['total'] === 0 && $metric['bestMonth'] !== 0)
				|| ($metric['total'] > 0 && ($metric['bestMonth'] < 1 || $metric['bestMonth'] > 12))
			)
			{
				throw new \UnexpectedValueException('The calculator returned an inconsistent metric pair');
			}
		}

		if (count($metrics) !== count($expectedUserIds))
		{
			throw new \UnexpectedValueException('The calculator returned an incomplete user map');
		}
	}
}
