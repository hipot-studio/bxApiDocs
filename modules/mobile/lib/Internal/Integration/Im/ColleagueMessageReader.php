<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Integration\Im;

use Bitrix\Im\Model\MessageTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Internal\Integration\Intranet\EmployeeReader;

/**
 * Who of a pack of users wrote to a colleague: a message sent into a private chat whose other member is an
 * active real employee of the portal. Whether that employee has finished registering is not asked.
 *
 * The pack is read in portions of CHUNK_SIZE, one query per portion led by the author. Null means im or
 * intranet is missing; a read that fails throws what it met, since a failure is not an unknown.
 */
class ColleagueMessageReader
{
	public const CHUNK_SIZE = 500;

	private readonly EmployeeReader $employees;

	/**
	 * @param EmployeeReader|null $employees who counts as a colleague, the intranet layer by default
	 */
	public function __construct(?EmployeeReader $employees = null)
	{
		$this->employees = $employees ?? new EmployeeReader();
	}

	/**
	 * @param int[] $userIds
	 * @return int[]|null the users of the pack who wrote to a colleague at or after the moment, to the second
	 */
	public function getSendersSince(array $userIds, DateTime $since): ?array
	{
		if (!$this->isModuleAvailable('im') || !$this->isModuleAvailable('intranet'))
		{
			return null;
		}

		$senderIds = [];
		foreach (array_chunk(self::normalize($userIds), self::CHUNK_SIZE) as $chunk)
		{
			foreach ($this->createSendersQuery($chunk, $since)->fetchAll() as $row)
			{
				$senderIds[] = (int)$row['AUTHOR_ID'];
			}
		}

		return $senderIds;
	}

	/**
	 * A portion of one author - the owner, asked about on every day of sending long after his step has
	 * closed - stops at the first message; a larger portion is grouped by the author.
	 *
	 * The companion test is a subquery rather than a join: it belongs to the intranet entity, while the
	 * member of the chat is an im one.
	 *
	 * @param int[] $userIds a non-empty portion
	 */
	protected function createSendersQuery(array $userIds, DateTime $since): Query
	{
		$query = MessageTable::query()
			->setSelect(['AUTHOR_ID'])
			->where('DATE_CREATE', '>=', $since)
			->where('CHAT.TYPE', Chat::IM_TYPE_PRIVATE)
			->whereColumn('RELATION.USER_ID', '!=', 'AUTHOR_ID')
			->whereIn('RELATION.USER_ID', $this->createEmployeeQuery())
		;

		if (count($userIds) === 1)
		{
			return $query->where('AUTHOR_ID', reset($userIds))->setLimit(1);
		}

		return $query->whereIn('AUTHOR_ID', $userIds)->setGroup(['AUTHOR_ID']);
	}

	/**
	 * A colleague as the intranet layer defines one (EmployeeReader): an active real employee.
	 */
	protected function createEmployeeQuery(): Query
	{
		return $this->employees->createColleagueQuery();
	}

	protected function isModuleAvailable(string $moduleId): bool
	{
		return Loader::includeModule($moduleId);
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
