<?php

namespace Bitrix\StaffTrack\Internal\Repository;

use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Internal\Model\OfficePresenceTable;

/**
 * Storage of the presence of employees: one row per person, addressed by the person, carrying the office
 * of the last confirmation and the two moments a status is derived from.
 *
 * Nothing here is read from the master on purpose: a row a couple of seconds stale moves both moments
 * back by those same seconds, while the thresholds that read them are an hour and a day.
 *
 * Which of the two moments a point raises is decided in PHP over the row that was read, and the update
 * holds that decision against the row it meets rather than the one it read: every assignment compares
 * with the column it writes and leaves a fresher value alone. Three requests write this row with no
 * lock between them - the two paths of acceptance and the signal of presence - and that comparison, not
 * a lock, a transaction or the master, is what keeps a moment from moving back. Both the insert and the
 * update still leave through the ordinary methods of the entity.
 *
 * Neither the insert nor the update leaves as an exception: the caller of the write stands past the commit
 * of the acceptance of a point and has to survive one, so a failure of either is answered with a sign,
 * which the caller journals in its own words, and the cause is journalled here, where alone it is known.
 * The reads are not wrapped, the one the write opens with included: whoever calls this survives a
 * throwable of the storage on their own.
 */
class OfficePresenceRepository
{
	/** Everything a row holds: a reader of presence needs all four and a fifth does not exist. */
	private const COLUMNS = ['USER_ID', 'OFFICE_ID', 'IN_ZONE_DATE', 'LAST_POINT_DATE'];

	/**
	 * @return array{USER_ID: int, OFFICE_ID: int, IN_ZONE_DATE: ?DateTime, LAST_POINT_DATE: DateTime}|null
	 */
	public function getByUserId(int $userId): ?array
	{
		$row = OfficePresenceTable::getByPrimary($userId, ['select' => self::COLUMNS])->fetch();

		return $row === false ? null : $row;
	}

	/**
	 * The row of an employee after a point of theirs: written whole if there is none, and raised moment
	 * by moment if there is.
	 *
	 * A call that raised neither moment - the ordinary outcome of a batch that came late - costs the one
	 * read above and no write at all.
	 *
	 * A moment the row got meanwhile, from a request that wrote between the read and the update, is not
	 * moved back by an update decided before it: the update holds each moment against the row it meets.
	 *
	 * The insert breaking against the primary key means a parallel request wrote the row between the read
	 * and the insert, and such a call is replayed as an update. The replay happens once: the same key
	 * broken twice would mean the row was taken away between the attempts, and that call is refused.
	 */
	public function write(int $userId, int $officeId, DateTime $lastPointDate, ?DateTime $inZoneDate): bool
	{
		$row = $this->getByUserId($userId);

		if ($row === null)
		{
			try
			{
				return $this->insert($userId, $officeId, $lastPointDate, $inZoneDate)->isSuccess();
			}
			catch (DuplicateEntryException)
			{
				// the core tells this one from any other failure by the code of the DBMS. The re-read
				// reaches the master by itself: the table is already marked as changed in this hit
				$row = $this->getByUserId($userId);

				if ($row === null)
				{
					return false;
				}
			}
			catch (SqlQueryException $e)
			{
				$this->logFailure('insert', $e);

				return false;
			}
		}

		$fields = $this->raisedFields($row, $officeId, $lastPointDate, $inZoneDate);

		if ($fields === [])
		{
			return true;
		}

		try
		{
			return OfficePresenceTable::update($userId, $this->monotonicAssignments($fields))->isSuccess();
		}
		catch (SqlQueryException $e)
		{
			$this->logFailure('update', $e);

			return false;
		}
	}

	/**
	 * The rows of a set of employees in one query whatever the size of the set: the page of the staff of
	 * an office is answered for as a whole, and asking person by person would cost a query per person.
	 *
	 * Whoever is missing from the answer has no row at all. The working employees are picked out by the
	 * read of the staff of an office long before this, so no predicate of that kind lives here.
	 *
	 * @param int[] $userIds
	 * @return array<int, array{USER_ID: int, OFFICE_ID: int, IN_ZONE_DATE: ?DateTime, LAST_POINT_DATE: DateTime}>
	 */
	public function findByUserIds(array $userIds): array
	{
		if ($userIds === [])
		{
			return [];
		}

		$rows = OfficePresenceTable::query()
			->setSelect(self::COLUMNS)
			->whereIn('USER_ID', array_map('intval', $userIds))
			->fetchAll()
		;

		$presence = [];

		foreach ($rows as $row)
		{
			$presence[(int)$row['USER_ID']] = $row;
		}

		return $presence;
	}

	/**
	 * The only place a row of presence is born. It is protected because a test replaces it to play the
	 * race: no second request can be raised inside one process.
	 *
	 * The office goes in unconditionally - the column is NOT NULL and the row has to carry the office of
	 * assignment from the start. While IN_ZONE_DATE is null nothing reads that office.
	 */
	protected function insert(int $userId, int $officeId, DateTime $lastPointDate, ?DateTime $inZoneDate): AddResult
	{
		return OfficePresenceTable::add([
			'USER_ID' => $userId,
			'OFFICE_ID' => $officeId,
			'LAST_POINT_DATE' => $lastPointDate,
			'IN_ZONE_DATE' => $inZoneDate,
		]);
	}

	/**
	 * The class of the failure, its reason and the frame it was thrown in, never the trace: the frames
	 * above this one carry the points of the employee, and the journal of the portal is not encrypted.
	 */
	private function logFailure(string $operation, SqlQueryException $e): void
	{
		AddMessage2Log(
			static::class . '::' . $operation . ' error: ' . $e::class . ': ' . $e->getMessage()
			. ' at ' . $e->getFile() . ':' . $e->getLine(),
		);
	}

	/**
	 * Which of the two moments the point raises, decided over the row that was read. Each is held against
	 * the one written in itself and nothing else, and the comparison is strict: an equal time changes
	 * nothing.
	 *
	 * The office travels with the confirmation and never on its own - a confirmation that did not pass
	 * the comparison was earned in a moment the row already knows a better one for.
	 *
	 * @param array{OFFICE_ID: int, IN_ZONE_DATE: ?DateTime, LAST_POINT_DATE: DateTime} $row
	 */
	private function raisedFields(array $row, int $officeId, DateTime $lastPointDate, ?DateTime $inZoneDate): array
	{
		$fields = [];

		if ($lastPointDate->getTimestamp() > $row['LAST_POINT_DATE']->getTimestamp())
		{
			$fields['LAST_POINT_DATE'] = $lastPointDate;
		}

		$confirmed = $row['IN_ZONE_DATE'];

		if ($inZoneDate !== null && ($confirmed === null || $inZoneDate->getTimestamp() > $confirmed->getTimestamp()))
		{
			$fields['IN_ZONE_DATE'] = $inZoneDate;
			$fields['OFFICE_ID'] = $officeId;
		}

		return $fields;
	}

	/**
	 * The raised fields as assignments that hold against the row the update meets: a moment fresher than
	 * the one offered, put there by a request that wrote between the read and this update, stays.
	 *
	 * The office follows the confirmation and reads IN_ZONE_DATE under "<=" rather than "<": MySQL
	 * assigns left to right and may already show the raised moment there, PostgreSQL shows the old one,
	 * and both answer the same under "<=".
	 *
	 * @param array{LAST_POINT_DATE?: DateTime, IN_ZONE_DATE?: DateTime, OFFICE_ID?: int} $fields
	 * @return array<string, SqlExpression>
	 */
	private function monotonicAssignments(array $fields): array
	{
		$assignments = [];

		if (isset($fields['LAST_POINT_DATE']))
		{
			$lastPointDate = $fields['LAST_POINT_DATE'];
			$assignments['LAST_POINT_DATE'] = new SqlExpression(
				'CASE WHEN ?# < ? THEN ? ELSE ?# END',
				'LAST_POINT_DATE',
				$lastPointDate,
				$lastPointDate,
				'LAST_POINT_DATE',
			);
		}

		if (isset($fields['IN_ZONE_DATE']))
		{
			$inZoneDate = $fields['IN_ZONE_DATE'];
			$assignments['IN_ZONE_DATE'] = new SqlExpression(
				'CASE WHEN ?# IS NULL OR ?# < ? THEN ? ELSE ?# END',
				'IN_ZONE_DATE',
				'IN_ZONE_DATE',
				$inZoneDate,
				$inZoneDate,
				'IN_ZONE_DATE',
			);
			$assignments['OFFICE_ID'] = new SqlExpression(
				'CASE WHEN ?# IS NULL OR ?# <= ? THEN ?i ELSE ?# END',
				'IN_ZONE_DATE',
				'IN_ZONE_DATE',
				$inZoneDate,
				$fields['OFFICE_ID'],
				'OFFICE_ID',
			);
		}

		return $assignments;
	}
}
