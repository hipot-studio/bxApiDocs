<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Integration\Tasks;

use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Internal\Integration\PerUserRights;
use Bitrix\Mobile\Internal\Integration\UnreadableRightsException;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\TaskAccessController;
use Bitrix\Tasks\Provider\Query\TaskQuery;
use Bitrix\Tasks\Provider\Query\TaskQueryBuilder;

/**
 * Tasks of a pack of users, read in portions of CHUNK_SIZE: one query per portion by the index that
 * starts with the author. A task already in the recycle bin still counts - it was made.
 *
 * The right to create a task is a separate answer: the records do not ask it, and it is not read from
 * the records, one user at a time. Null means the tasks module is missing; a read that fails throws what
 * it met, since a failure is not an unknown - a right fails only the users it could not be read for.
 */
class TaskReader
{
	public const CHUNK_SIZE = 500;

	/**
	 * @param int[] $userIds
	 * @return int[]|null the users of the pack who made a task at or after the moment, to the second
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
				$authorIds[] = (int)$row['CREATED_BY'];
			}
		}

		return $authorIds;
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, bool>|null userId => the right to create a task
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
	 * A portion of one author - the owner, asked about on every day of sending long after his step has
	 * closed - stops at the first task; a larger portion is grouped by the author.
	 *
	 * @param int[] $userIds a non-empty portion
	 */
	protected function createAuthorsQuery(array $userIds, DateTime $since): Query
	{
		$query = (new TaskQuery())
			->skipAccessCheck()
			->setDistinct(false)
			->setSelect(['CREATED_BY'])
			->setWhere(['>=CREATED_DATE' => $since]);

		if (count($userIds) === 1)
		{
			$query->andWhere(['=CREATED_BY' => reset($userIds)])->setLimit(1);
		}
		else
		{
			$query->andWhere(['@CREATED_BY' => $userIds])->setGroupBy(['CREATED_BY']);
		}

		return TaskQueryBuilder::build($query);
	}

	protected function readCreateRight(int $userId): bool
	{
		return TaskAccessController::can($userId, ActionDictionary::ACTION_TASK_CREATE);
	}

	protected function isModuleAvailable(): bool
	{
		return Loader::includeModule('tasks');
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
