<?php

namespace Bitrix\StaffTrack\Internal\Repository;

use Bitrix\Main\Application;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\DeleteResult;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\StaffTrack\Internal\Geo\Distance;
use Bitrix\StaffTrack\Internal\Model\Office;
use Bitrix\StaffTrack\Internal\Model\OfficeTable;
use Bitrix\StaffTrack\Internal\Service\Office\OfficeCardVersion;
use Bitrix\StaffTrack\Internal\Service\Office\StoredOfficeAddress;

/**
 * Storage of the office card. Rights, transactions and the fields of an update - DATE_UPDATE and
 * UPDATED_BY among them - belong to the command calling this, not here. The name of the lock of an
 * office is of the storage and lives here; who takes it and around what is again up to the caller.
 */
class OfficeRepository
{
	/** How long an operation waits for the office it needs, in seconds. */
	private const LOCK_TIMEOUT = 5;

	/** The map another request is already taking is not waited for. */
	private const SNAPSHOT_LOCK_TIMEOUT = 0;

	public function getById(int $id): ?Office
	{
		return OfficeTable::query()
			->setSelect(['*'])
			->where('ID', $id)
			->fetchObject()
		;
	}

	public function findStoredAddress(int $id): ?StoredOfficeAddress
	{
		$office = OfficeTable::query()
			->setSelect(['ADDRESS', 'LATITUDE', 'LONGITUDE'])
			->where('ID', $id)
			->fetchObject()
		;

		return $office === null ? null : StoredOfficeAddress::fromOffice($office);
	}

	public function findCardVersion(int $id): ?string
	{
		$office = OfficeTable::query()
			->setSelect(['TITLE', 'LATITUDE', 'LONGITUDE', 'GEO_RADIUS', 'ADDRESS', 'WORK_TIME_FROM', 'WORK_TIME_TO'])
			->where('ID', $id)
			->fetchObject()
		;

		return $office === null ? null : OfficeCardVersion::versionOf($office);
	}

	/**
	 * The whole directory, alphabetically; the identifier settles the order of offices sharing a title.
	 *
	 * @return Office[]
	 */
	public function getList(): array
	{
		return OfficeTable::query()
			->setSelect(['*'])
			->setOrder(['TITLE' => 'ASC', 'ID' => 'ASC'])
			->fetchCollection()
			->getAll()
		;
	}

	/**
	 * @return Office[]
	 */
	public function getListLimited(int $limit): array
	{
		return $this->getSelectorQuery()
			->setLimit($limit)
			->fetchCollection()
			->getAll()
		;
	}

	/**
	 * @return Office[]
	 */
	public function findByTitleSubstring(string $query, int $limit): array
	{
		$escapedQuery = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);

		return $this->getSelectorQuery()
			->whereLike('TITLE', '%' . $escapedQuery . '%')
			->setLimit($limit)
			->fetchCollection()
			->getAll()
		;
	}

	/**
	 * @param int[] $ids
	 * @return Office[]
	 */
	public function getByIds(array $ids): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
		if (empty($ids))
		{
			return [];
		}

		return $this->getSelectorQuery()
			->whereIn('ID', $ids)
			->fetchCollection()
			->getAll()
		;
	}

	/**
	 * @param int[] $ids
	 * @return array<int, string> office id => title; an office that is gone is left out
	 */
	public function getTitlesByIds(array $ids): array
	{
		if ($ids === [])
		{
			return [];
		}

		$rows = OfficeTable::query()
			->setSelect(['ID', 'TITLE'])
			->whereIn('ID', $ids)
			->fetchAll()
		;

		$titles = [];
		foreach ($rows as $row)
		{
			$titles[(int)$row['ID']] = (string)$row['TITLE'];
		}

		return $titles;
	}

	/**
	 * The offices of a band of latitude: every one whose latitude stands no further than $meters from
	 * the given one, measured along the meridian by {@see Distance} - the very arithmetic that weighs the
	 * distance over the answer afterwards, so sieve and verdict measure the Earth alike. A rough sieve of
	 * the check for overlapping zones and nothing more: the distance never gives less than the gap in
	 * latitude alone, so an office this band leaves out cannot be an overlapping one.
	 *
	 * Longitude is deliberately left unbounded. A bound on it would carry the crossing of the antimeridian
	 * and the cosine collapsing at the poles into the query and buy next to no selectivity in return: the
	 * band already stands on the leading column of ix_stafftrack_office_coords.
	 *
	 * @return array[] rows of ID, TITLE, LATITUDE, LONGITUDE and GEO_RADIUS as the storage answers with
	 *                 them, ordered by identifier
	 */
	public function findByLatitudeBand(float $latitude, int $meters): array
	{
		$delta = Distance::latitudeDegreesFor($meters);

		return OfficeTable::query()
			->setSelect(['ID', 'TITLE', 'LATITUDE', 'LONGITUDE', 'GEO_RADIUS'])
			->whereBetween('LATITUDE', $latitude - $delta, $latitude + $delta)
			->setOrder(['ID' => 'ASC'])
			->fetchAll()
		;
	}

	/**
	 * The widest zone the directory holds, nothing at all when it holds no office. It is read from the
	 * rows rather than taken from {@see \Bitrix\StaffTrack\Dictionary\OfficeGeoRadius}: the column is
	 * bound by the form of the card alone, and a band narrower than the widest zone actually stored
	 * would miss the office that zone belongs to.
	 */
	public function getMaxGeoRadius(): ?int
	{
		$row = OfficeTable::query()
			->registerRuntimeField(new ExpressionField('MAX_GEO_RADIUS', 'MAX(%s)', ['GEO_RADIUS']))
			->setSelect(['MAX_GEO_RADIUS'])
			->exec()
			->fetch()
		;

		$maxRadius = $row['MAX_GEO_RADIUS'] ?? null;

		return $maxRadius === null ? null : (int)$maxRadius;
	}

	public function getCount(): int
	{
		return (int)OfficeTable::getCount();
	}

	public function add(array $fields): AddResult
	{
		return OfficeTable::add($fields);
	}

	public function update(int $id, array $fields): UpdateResult
	{
		return OfficeTable::update($id, $fields);
	}

	public function delete(int $id): DeleteResult
	{
		return OfficeTable::delete($id);
	}

	public function getSnapshotFileId(int $id): ?int
	{
		$row = OfficeTable::query()
			->setSelect(['SNAPSHOT_FILE_ID'])
			->where('ID', $id)
			->exec()
			->fetch()
		;

		$fileId = (int)($row['SNAPSHOT_FILE_ID'] ?? 0);

		return $fileId > 0 ? $fileId : null;
	}

	/**
	 * The map of the office read inside the transaction that removes it: the row stays locked until
	 * the commit, so a snapshot taken meanwhile cannot slip a new file in between this read and the
	 * removal and be left in the storage with nothing naming it.
	 */
	public function getSnapshotFileIdForUpdate(int $id): ?int
	{
		$connection = Application::getConnection();
		$tableName = OfficeTable::getTableName();

		$row = $connection
			->query("SELECT SNAPSHOT_FILE_ID FROM {$tableName} WHERE ID = {$id} FOR UPDATE")
			->fetch()
		;

		$fileId = (int)($row['SNAPSHOT_FILE_ID'] ?? 0);

		return $fileId > 0 ? $fileId : null;
	}

	/**
	 * @return bool False when the office is gone or its map is no longer $expectedFileId.
	 */
	public function replaceSnapshotFileId(int $id, ?int $expectedFileId, int $fileId): bool
	{
		$connection = Application::getConnection();
		$tableName = OfficeTable::getTableName();
		$expected = $expectedFileId === null
			? 'SNAPSHOT_FILE_ID IS NULL'
			: 'SNAPSHOT_FILE_ID = ' . $expectedFileId
		;

		$connection->queryExecute(
			"UPDATE {$tableName} SET SNAPSHOT_FILE_ID = {$fileId} WHERE ID = {$id} AND {$expected}",
		);

		return $connection->getAffectedRowsCount() > 0;
	}

	/**
	 * A named lock of one office, taken by the operations that must not overlap: the removal of an
	 * office and the addition of photos to it. The lock belongs to the database rather than to the
	 * process, so it holds between the nodes a portal runs on.
	 *
	 * @return bool False when the office is held by another operation for longer than the wait allows.
	 */
	public function lock(int $id): bool
	{
		return Application::getConnection()->lock($this->getLockName($id), self::LOCK_TIMEOUT);
	}

	public function unlock(int $id): void
	{
		Application::getConnection()->unlock($this->getLockName($id));
	}

	/**
	 * A lock of its own, not the one of the card: taking a map waits on an external service, and the
	 * removal of an office must not queue behind it.
	 */
	public function lockSnapshot(int $id): bool
	{
		return Application::getConnection()->lock($this->getSnapshotLockName($id), self::SNAPSHOT_LOCK_TIMEOUT);
	}

	public function unlockSnapshot(int $id): void
	{
		Application::getConnection()->unlock($this->getSnapshotLockName($id));
	}

	private function getLockName(int $id): string
	{
		return 'stafftrack.office.' . $id;
	}

	private function getSnapshotLockName(int $id): string
	{
		return 'stafftrack.office.snapshot.' . $id;
	}

	private function getSelectorQuery(): Query
	{
		return OfficeTable::query()
			->setSelect(['ID', 'TITLE', 'ADDRESS'])
			->setOrder(['TITLE' => 'ASC', 'ID' => 'ASC'])
		;
	}
}
