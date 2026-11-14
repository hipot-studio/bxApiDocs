<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Internal\Integration\Intranet\AnnualSummary;

use Bitrix\Intranet\Internal\Entity\AnnualSummary\Summary;
use Bitrix\Intranet\Public\Provider\AnnualSummary\SummaryProviderInterface;
use Bitrix\Main\Entity\EntityCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Date;
use Bitrix\StaffTrack\Internal\Repository\AnnualCheckInCountRepository;

if (!Loader::includeModule('intranet'))
{
	return;
}

final class CheckInSummaryProvider implements SummaryProviderInterface
{
	public const ID = 'stafftrack.checkins';
	public const USER_ID_LIMIT = 5000;

	private int $lastUserId = 0;
	private readonly AnnualCheckInCountRepository $countRepository;

	public function __construct(
		private readonly Date $from,
		private readonly Date $to,
		?AnnualCheckInCountRepository $countRepository = null,
	)
	{
		$this->countRepository = $countRepository ?? new AnnualCheckInCountRepository();
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

	/**
	 * @param int[] $userIds
	 */
	public function provide(array $userIds): EntityCollection
	{
		$userIds = $this->normalizeUserIds($userIds);
		if (empty($userIds))
		{
			$this->lastUserId = 0;

			return new EntityCollection();
		}

		$this->lastUserId = max($userIds);

		try
		{
			$totals = $this->countRepository->countByUsers($userIds, $this->from, $this->to);
		}
		catch (\Throwable $exception)
		{
			AddMessage2Log(sprintf(
				'stafftrack annual summary: failed to count check-ins of %d users (ids %d..%d, period %s..%s): %s',
				count($userIds),
				min($userIds),
				max($userIds),
				$this->from->format('Y-m-d'),
				$this->to->format('Y-m-d'),
				$exception->getMessage(),
			), 'stafftrack');

			return new EntityCollection();
		}

		$collection = new EntityCollection();
		foreach ($userIds as $userId)
		{
			$collection->add(new Summary($userId, self::ID, $totals[$userId] ?? 0));
		}

		return $collection;
	}

	/**
	 * @return int[]
	 */
	private function normalizeUserIds(array $userIds): array
	{
		$userIds = array_unique(array_filter(
			array_map(static fn($userId): int => (int)$userId, $userIds),
			static fn(int $userId): bool => $userId > 0,
		));
		sort($userIds);

		return $userIds;
	}
}
