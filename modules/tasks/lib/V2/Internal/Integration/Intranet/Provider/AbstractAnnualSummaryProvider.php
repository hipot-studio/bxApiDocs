<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Intranet\Provider;

use Bitrix\Intranet\Internal\Entity\AnnualSummary\Summary;
use Bitrix\Intranet\Public\Provider\AnnualSummary\SummaryProviderInterface;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Entity\EntityCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Date;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

if (!Loader::includeModule('intranet'))
{
	return;
}

abstract class AbstractAnnualSummaryProvider implements SummaryProviderInterface
{
	private const LOGGER_ID = 'tasks.annual_summary_provider';

	private int $lastUserId = 0;
	private readonly LoggerInterface $logger;

	public function __construct(
		protected readonly Date $from,
		protected readonly Date $to,
		?LoggerInterface $logger = null,
	)
	{
		$this->logger = $logger ?? (new LoggerFactory())->createById(self::LOGGER_ID) ?? new NullLogger();
	}

	public function getLastUserId(): int
	{
		return $this->lastUserId;
	}

	public function provide(array $userIds): EntityCollection
	{
		$collection = new EntityCollection();
		if (empty($userIds))
		{
			return $collection;
		}

		$userIds = array_map(static fn ($userId): int => (int)$userId, $userIds);
		$lastUserId = max($userIds);

		try
		{
			$counts = $this->normalizeCounts($this->countByUsers($userIds));
			foreach ($userIds as $userId)
			{
				$collection->add(new Summary($userId, $this->getId(), $counts[$userId] ?? 0));
			}

			$this->lastUserId = $lastUserId;
		}
		catch (Throwable $throwable)
		{
			$this->logger->error('Failed to provide annual summary values.', [
				'providerId' => $this->getId(),
				'from' => $this->from->format('Y-m-d'),
				'to' => $this->to->format('Y-m-d'),
				'userIds' => $userIds,
				'lastUserId' => $this->lastUserId,
				'exception' => $throwable,
			]);

			return new EntityCollection();
		}

		return $collection;
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, int>
	 */
	abstract protected function countByUsers(array $userIds): array;

	/**
	 * @return array<int, int>
	 */
	private function normalizeCounts(array $counts): array
	{
		$normalizedCounts = [];
		foreach ($counts as $userId => $count)
		{
			$normalizedCounts[(int)$userId] = (int)$count;
		}

		return $normalizedCounts;
	}
}
