<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Quota;

use Bitrix\Disk\Internal\Enum\UsageStatus;
use Bitrix\Disk\Internal\Model\UserQuotaTable;
use Bitrix\Disk\Ui\Avatar;
use Bitrix\Main\Application;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Search\Content;
use Bitrix\Main\UserIndexTable;
use Bitrix\Main\UserTable;

/**
 * Reads the employees who have a personal quota assigned - a page of them, or one of them.
 *
 * A page costs the count of the employees found and one query for the rows themselves, whatever the
 * length of the list: the used size comes off the stored counter and is aggregated nowhere here. The
 * quota row of an employee who left the portal is kept, and the list simply passes them by - the row
 * waits for them to be activated again.
 */
class QuotaListProvider
{
	public const SORT_QUOTA = 'quota';
	public const SORT_USED = 'used';
	public const SORT_USAGE = 'usage';

	public const ORDER_ASC = 'asc';
	public const ORDER_DESC = 'desc';

	private const USER_RELATION = 'USER';
	private const USER_INDEX_RELATION = 'USER_INDEX';

	private const USAGE_UNKNOWN_FIELD = 'USAGE_IS_UNKNOWN';
	private const KNOWN_USED_SIZE_FIELD = 'KNOWN_USED_SIZE';
	private const USAGE_RATIO_FIELD = 'USAGE_RATIO';

	/** What every sortable name of the contract stands for; a name outside this list reaches no sql. */
	private const SORT_KEYS = [
		self::SORT_QUOTA => 'QUOTA_SIZE',
		self::SORT_USED => self::KNOWN_USED_SIZE_FIELD,
		self::SORT_USAGE => self::USAGE_RATIO_FIELD,
	];

	public static function isSortSupported(string $sort): bool
	{
		return isset(self::SORT_KEYS[$sort]);
	}

	public static function isOrderSupported(string $order): bool
	{
		return $order === self::ORDER_ASC || $order === self::ORDER_DESC;
	}

	/**
	 * Employees with a quota, a page of them, with the last known state of their storage.
	 *
	 * The number of employees found is counted first, and a page that begins past it is answered
	 * with no rows rather than read: the last page of the outcome stays reachable however deep it
	 * lies, and a page beyond it costs no walk through the join.
	 *
	 * @param int $offset First row of the page.
	 * @param int $limit Size of the page.
	 * @param string|null $search Words to look the employee up by, full text.
	 * @param string|null $sort Column the rows are ordered by, null for the order by default.
	 * @param string|null $order Direction of that column, ascending unless it says otherwise.
	 * @return array{items: array[], total: int} Rows of the page and the number of employees found.
	 */
	public function getPage(
		int $offset,
		int $limit,
		?string $search = null,
		?string $sort = null,
		?string $order = null,
	): array
	{
		$total = (int)$this->buildQuery($search)->queryCountTotal();
		if ($offset >= $total)
		{
			return ['items' => [], 'total' => $total];
		}

		$query = $this->buildQuery($search);
		$this->applyOrder($query, $sort, $order);
		$query->setOffset($offset);
		$query->setLimit($limit);

		$rows = $query->exec()->fetchAll();

		Avatar::preload(array_column($rows, 'USER_PERSONAL_PHOTO'));

		return [
			'items' => array_map($this->buildItem(...), $rows),
			'total' => $total,
		];
	}

	/**
	 * The row of one employee, assembled exactly as the page assembles it.
	 *
	 * Answers with null where the list shows no such employee: the limit of somebody the section
	 * does not manage is stored all the same, and there is simply no row of the list to answer with.
	 *
	 * @param int $userId Employee the row belongs to.
	 * @return array|null
	 */
	public function getItem(int $userId): ?array
	{
		$query = $this->buildQuery(null);
		$query->where('USER_ID', $userId);
		$query->setLimit(1);

		$row = $query->exec()->fetch();
		if (!is_array($row))
		{
			return null;
		}

		Avatar::preload([$row['USER_PERSONAL_PHOTO']]);

		return $this->buildItem($row);
	}

	private function buildQuery(?string $search): Query
	{
		$query = UserQuotaTable::query();
		$query->registerRuntimeField(new Reference(
			self::USER_RELATION,
			UserTable::class,
			Join::on('this.USER_ID', 'ref.ID'),
			['join_type' => 'INNER'],
		));
		$query->setSelect([
			'USER_ID',
			'QUOTA_SIZE',
			'USED_SIZE',
			'USED_SIZE_STATUS',
			'USER_NAME' => self::USER_RELATION . '.NAME',
			'USER_SECOND_NAME' => self::USER_RELATION . '.SECOND_NAME',
			'USER_LAST_NAME' => self::USER_RELATION . '.LAST_NAME',
			'USER_LOGIN' => self::USER_RELATION . '.LOGIN',
			'USER_EMAIL' => self::USER_RELATION . '.EMAIL',
			'USER_PERSONAL_PHOTO' => self::USER_RELATION . '.PERSONAL_PHOTO',
			// the table of users is joined for the name anyway, so the position costs no query
			'USER_WORK_POSITION' => self::USER_RELATION . '.WORK_POSITION',
		]);

		// Dismissal is deactivation, and a service account is nobody to assign a limit to.
		$query->where(self::USER_RELATION . '.ACTIVE', 'Y');
		$query->where(self::USER_RELATION . '.REAL_USER', 'expr', true);

		$this->applySearch($query, $search);

		return $query;
	}

	/**
	 * Turns the sorting of the contract into an order the database does over the whole outcome.
	 *
	 * The name that arrives is matched against the closed list of the contract and never reaches the
	 * sql; an unknown one is refused by the action before it gets here. The employee closes every
	 * order: rows equal in the sorted column would drift between neighbouring pages otherwise, and a
	 * portal where everybody was given the same limit is exactly such a case.
	 */
	private function applyOrder(Query $query, ?string $sort, ?string $order): void
	{
		if ($sort === null || !self::isSortSupported($sort))
		{
			$query->setOrder(['USER_ID' => 'ASC']);

			return;
		}

		$rules = [];

		if (in_array($sort, [self::SORT_USED, self::SORT_USAGE], true))
		{
			// what has never been counted is no size at all, so it stands last either way round (AC-069)
			$this->registerUsageFields($query);
			$rules[self::USAGE_UNKNOWN_FIELD] = 'ASC';
		}

		$rules[self::SORT_KEYS[$sort]] = $order === self::ORDER_DESC ? 'DESC' : 'ASC';
		$rules['USER_ID'] = 'ASC';

		$query->setOrder($rules);
	}

	/**
	 * The keys the order of the usage is built of.
	 *
	 * The ratio is counted in real numbers on purpose: postgresql truncates the division of two
	 * integers, and everybody filled below the brim would share one key. Dividing by zero cannot
	 * happen - a quota is a gigabyte at the least, {@see \Bitrix\Disk\Public\Command\Quota\SetUserQuotaCommand}.
	 *
	 * An unknown usage is not a zero: which group a row lands in is decided by USAGE_IS_UNKNOWN
	 * alone, and the sizes are only made free of nulls so that the rows of one group keep the same
	 * order on both databases.
	 */
	private function registerUsageFields(Query $query): void
	{
		$calculating = Application::getConnection()
			->getSqlHelper()
			->convertToDbString(UsageStatus::Calculating->value)
		;

		$query->registerRuntimeField(new ExpressionField(
			self::USAGE_UNKNOWN_FIELD,
			"CASE WHEN %s IS NULL OR %s = {$calculating} THEN 1 ELSE 0 END",
			['USED_SIZE', 'USED_SIZE_STATUS'],
		));
		$query->registerRuntimeField(new ExpressionField(
			self::KNOWN_USED_SIZE_FIELD,
			'COALESCE(%s, 0)',
			['USED_SIZE'],
		));
		$query->registerRuntimeField(new ExpressionField(
			self::USAGE_RATIO_FIELD,
			'COALESCE(%s, 0) * 1.0 / %s',
			['USED_SIZE', 'QUOTA_SIZE'],
		));
	}

	/**
	 * Looks the employee up in the full text index of users instead of the name the administrator
	 * sees: that name is assembled in php by the name template of the portal and exists in no column.
	 *
	 * The administrative half of the index is read, the one that covers the login and the mail beside
	 * the name, {@see \Bitrix\Main\UserTable::generateSearchAdminContent()}. The section belongs to a
	 * full administrator of the portal, who is shown those very fields in the list of employees.
	 *
	 * Words shorter than the smallest token of the index are left out of the query: the index keeps
	 * none of them, and the words of a search are asked for together, so one of those would answer
	 * for nobody at all - a mail ending in a two letter domain, or a name like "Ян Иванов". A search
	 * made of such words alone is nothing the index can be asked about, and answers with nobody.
	 */
	private function applySearch(Query $query, ?string $search): void
	{
		$token = Content::prepareStringToken(trim((string)$search));
		if ($token === '')
		{
			return;
		}

		$match = Filter\Helper::matchAgainstWildcard($token, '*');
		if ($match === '')
		{
			$query->whereExpr('1 = 0', []);

			return;
		}

		$query->registerRuntimeField(new Reference(
			self::USER_INDEX_RELATION,
			UserIndexTable::class,
			Join::on('this.USER_ID', 'ref.USER_ID'),
			['join_type' => 'INNER'],
		));
		$query->whereMatch(self::USER_INDEX_RELATION . '.SEARCH_ADMIN_CONTENT', $match);
	}

	private function buildItem(array $row): array
	{
		$quotaSize = (int)$row['QUOTA_SIZE'];
		$usedSize = $row['USED_SIZE'] === null ? null : (int)$row['USED_SIZE'];
		$status = UsageStatus::tryFrom((string)$row['USED_SIZE_STATUS']) ?? UsageStatus::Stale;

		return [
			'userId' => (int)$row['USER_ID'],
			'userName' => $this->formatName($row),
			'userPosition' => $this->formatPosition($row),
			'userAvatar' => Avatar::getSrc((int)$row['USER_PERSONAL_PHOTO']) ?: null,
			'quotaSize' => $quotaSize,
			'usedSize' => $usedSize,
			'usageStatus' => $status->value,
			'usagePercent' => $this->calculateUsagePercent($usedSize, $quotaSize, $status),
			'isOverLimit' => $usedSize !== null && $usedSize > $quotaSize,
		];
	}

	/**
	 * A position nobody filled in and a position filled in with nothing are one and the same for the
	 * list: telling them apart would give the row a second line with nothing on it.
	 */
	private function formatPosition(array $row): ?string
	{
		$position = trim((string)$row['USER_WORK_POSITION']);

		return $position === '' ? null : $position;
	}

	private function formatName(array $row): string
	{
		return (string)\CUser::formatName(\CSite::getNameFormat(), [
			'NAME' => $row['USER_NAME'],
			'LAST_NAME' => $row['USER_LAST_NAME'],
			'SECOND_NAME' => $row['USER_SECOND_NAME'],
			'EMAIL' => $row['USER_EMAIL'],
			'ID' => $row['USER_ID'],
			'LOGIN' => $row['USER_LOGIN'],
		], true, false);
	}

	/**
	 * Rounds down: the interface reads a hundred as a quota fully taken, and rounding to the nearest
	 * would report that of 19.996 gigabytes out of 20. A size still being recalculated is no percent
	 * at all - the row shows the recalculation instead.
	 */
	private function calculateUsagePercent(?int $usedSize, int $quotaSize, UsageStatus $status): ?int
	{
		if ($usedSize === null || $status === UsageStatus::Calculating || $quotaSize <= 0)
		{
			return null;
		}

		return (int)floor($usedSize * 100 / $quotaSize);
	}
}
