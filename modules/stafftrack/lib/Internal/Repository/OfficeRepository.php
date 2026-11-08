<?php

namespace Bitrix\StaffTrack\Internal\Repository;

use Bitrix\Main\Application;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\DeleteResult;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\StaffTrack\Internal\Model\Office;
use Bitrix\StaffTrack\Internal\Model\OfficeTable;

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
}
