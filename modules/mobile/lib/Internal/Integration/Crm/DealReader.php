<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Integration\Crm;

use Bitrix\Crm\DealTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Internal\Integration\PerUserRights;
use Bitrix\Mobile\Internal\Integration\ServiceFactory;
use Bitrix\Mobile\Internal\Integration\UnreadableRightsException;

/**
 * Deals of a pack of users, read in portions of CHUNK_SIZE: one grouped query per portion. A deal in
 * the recycle bin is gone from the table, so it does not count.
 *
 * The right to create a deal is a separate answer: the records do not ask it, and it is not read from
 * the records, one user at a time. Null means the crm module is missing; a read that fails throws what
 * it met, since a failure is not an unknown - a right fails only the users it could not be read for.
 */
class DealReader
{
	public const CHUNK_SIZE = 500;
	private ?Container $container;
	private readonly ServiceFactory $services;

	public function __construct(?Container $container = null, ?ServiceFactory $services = null)
	{
		$this->container = $container;
		$this->services = $services ?? new ServiceFactory();
	}

	/**
	 * @param int[] $userIds
	 * @return int[]|null the users of the pack who made a deal at or after the moment, to the second
	 */
	public function getAuthorsSince(array $userIds, DateTime $since): ?array
	{
		if (!$this->isModuleAvailable())
		{
			return null;
		}

		$authorIds = [];
		foreach (array_chunk(self::normalize($userIds), self::CHUNK_SIZE) as $chunk)
		{
			foreach ($this->createAuthorsQuery($chunk, $since)->fetchAll() as $row)
			{
				$authorIds[] = (int)$row['CREATED_BY_ID'];
			}
		}

		return $authorIds;
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, bool>|null userId => the right to create a deal in some pipeline
	 * @throws UnreadableRightsException naming the users whose right could not be read, with the rights
	 *     of the others
	 */
	public function canCreate(array $userIds): ?array
	{
		if (!$this->isModuleAvailable())
		{
			return null;
		}

		return PerUserRights::read(
			self::normalize($userIds),
			fn(int $userId): bool => $this->readCreateRight($userId),
		);
	}

	/**
	 * No index of b_crm_deal starts with the author, so one query per user would read the date range again
	 * for each of them; grouped, the range is read once per portion.
	 *
	 * @param int[] $userIds a non-empty portion
	 */
	protected function createAuthorsQuery(array $userIds, DateTime $since): Query
	{
		return DealTable::query()
			->setSelect(['CREATED_BY_ID'])
			->whereIn('CREATED_BY_ID', $userIds)
			->where('DATE_CREATE', '>=', $since)
			->setGroup(['CREATED_BY_ID'])
		;
	}

	protected function readCreateRight(int $userId): bool
	{
		$this->container ??= $this->services->crmContainer();

		return $this->container->getUserPermissions($userId)->entityType()->canAddItems(\CCrmOwnerType::Deal);
	}

	protected function isModuleAvailable(): bool
	{
		return Loader::includeModule('crm');
	}

	/**
	 * @param array<int|string> $userIds
	 * @return int[]
	 */
	private static function normalize(array $userIds): array
	{
		return array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			static fn(int $userId): bool => $userId > 0,
		)));
	}
}
