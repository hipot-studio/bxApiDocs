<?php

namespace Bitrix\BIConnector\Internal\Services\TableCell;

use Bitrix\BIConnector\Access\Permission\PermissionDictionary;
use Bitrix\BIConnector\Access\Permission\PermissionTable;
use Bitrix\BIConnector\Access\Role\RoleUtil;
use Bitrix\BIConnector\Access\TableGroup\TableGroupBindingTable;
use Bitrix\BIConnector\Public\Command\TableCell\TableCellChange;
use Bitrix\BIConnector\Public\Command\TableCell\TableRights;
use Bitrix\BIConnector\Superset\Error\UserFacingError;
use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

/**
 * Writes the cells of a role and a table: the TT<code> rows of the role in the permission table.
 *
 * The rows are written with raw inserts and deletions only: a row of the empty value is how "nothing is
 * allowed" is stored, and the ORM of the permission table would treat a code with a dot as a hierarchy.
 *
 * Opens no transaction and resets no cache: the caller holds the transaction and notifies after its commit.
 */
final class TableCellService
{
	public const ERROR_VIEW_REQUIRED = 'BICONNECTOR_TABLE_CELL_VIEW_REQUIRED';
	public const ERROR_TABLE_WITHOUT_GROUP = 'BICONNECTOR_TABLE_CELL_TABLE_WITHOUT_GROUP';
	public const ERROR_ROLE_NOT_FOUND = 'BICONNECTOR_TABLE_CELL_ROLE_NOT_FOUND';
	public const ERROR_INVALID = 'BICONNECTOR_TABLE_CELL_INVALID';

	private const MAX_TABLE_CODE_LENGTH = 250;

	private const NOTHING_ALLOWED_VALUE = 0;

	private const DELETE_CHUNK_SIZE = 500;

	private readonly RoleLocker $roleLocker;

	/**
	 * @param RoleLocker|null $roleLocker The locker of the transaction of the save, which has locked the roles before.
	 */
	public function __construct(?RoleLocker $roleLocker = null)
	{
		$this->roleLocker = $roleLocker ?? new RoleLocker();
	}

	/**
	 * Checks every change and only then writes them: the first refusal leaves every cell as it was.
	 *
	 * @param TableCellChange[] $changes
	 *
	 * @return Result Data: ['isChanged' => bool] - whether any row was deleted or inserted.
	 */
	public function save(array $changes): Result
	{
		$result = new Result();

		$checkResult = $this->check($changes);
		if (!$checkResult->isSuccess())
		{
			return $result->addErrors($checkResult->getErrors());
		}

		return $result->setData(['isChanged' => $this->write($changes)]);
	}

	/**
	 * Turns the tableCells of a role element of the matrix payload into changes.
	 *
	 * @param int $roleId Id of the role the cells belong to, the real one after the role is saved.
	 * @param mixed $tableCells array<string, TableRights|null>: null - the role inherits the rights of the groups.
	 *
	 * @return Result Data: ['changes' => TableCellChange[]].
	 */
	public function collectFromPayload(int $roleId, mixed $tableCells): Result
	{
		$result = new Result();

		if (!is_array($tableCells))
		{
			return $result->addError($this->createInvalidError($roleId, null));
		}

		$changes = [];
		foreach ($tableCells as $tableCode => $rights)
		{
			// an array key made of digits arrives as an integer
			$tableCode = (string)$tableCode;
			if ($rights !== null && !$rights instanceof TableRights)
			{
				return $result->addError($this->createInvalidError($roleId, $tableCode));
			}

			$changes[] = new TableCellChange($roleId, $tableCode, $rights);
		}

		return $result->setData(['changes' => $changes]);
	}

	/**
	 * Deletes the cells of the tables in every role.
	 *
	 * @param string[] $tableCodes
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByTableCodes(array $tableCodes): int
	{
		$permissionIds = [];
		foreach ($tableCodes as $tableCode)
		{
			$tableCode = (string)$tableCode;
			if ($tableCode !== '')
			{
				$permissionId = PermissionDictionary::getTableCellPermissionId($tableCode);
				$permissionIds[$permissionId] = $permissionId;
			}
		}

		if (empty($permissionIds))
		{
			return 0;
		}

		PermissionTable::deleteList(['@PERMISSION_ID' => array_values($permissionIds)]);

		return (int)Application::getConnection()->getAffectedRowsCount();
	}

	/**
	 * @param array $changes
	 *
	 * @return Result The first refusal: invalid data, then view required, then a missing role,
	 *         then a table without a group.
	 */
	private function check(array $changes): Result
	{
		$result = new Result();

		$pairs = [];
		foreach ($changes as $change)
		{
			if (!$change instanceof TableCellChange)
			{
				return $result->addError($this->createInvalidError(null, null));
			}

			if (
				$change->roleId <= 0
				|| $change->tableCode === ''
				|| mb_strlen($change->tableCode) > self::MAX_TABLE_CODE_LENGTH
				|| isset($pairs[$change->roleId][$change->tableCode])
			)
			{
				return $result->addError($this->createInvalidError($change->roleId, $change->tableCode));
			}

			$pairs[$change->roleId][$change->tableCode] = true;
		}

		foreach ($changes as $change)
		{
			$rights = $change->rights;
			if ($rights !== null && !$rights->view && ($rights->edit || $rights->export))
			{
				return $result->addError(new UserFacingError(
					(string)Loc::getMessage('BICONNECTOR_TABLE_CELL_ERROR_VIEW_REQUIRED'),
					self::ERROR_VIEW_REQUIRED,
					['roleId' => $change->roleId, 'tableCode' => $change->tableCode],
				));
			}
		}

		$missingRoleId = $this->findMissingRoleId($changes);
		if ($missingRoleId !== null)
		{
			return $result->addError(new UserFacingError(
				(string)Loc::getMessage('BICONNECTOR_TABLE_CELL_ERROR_ROLE_NOT_FOUND'),
				self::ERROR_ROLE_NOT_FOUND,
				['roleId' => $missingRoleId],
			));
		}

		$unboundChange = $this->findOverrideOfUnboundTable($changes);
		if ($unboundChange !== null)
		{
			return $result->addError(new UserFacingError(
				(string)Loc::getMessage('BICONNECTOR_TABLE_CELL_ERROR_TABLE_WITHOUT_GROUP'),
				self::ERROR_TABLE_WITHOUT_GROUP,
				['roleId' => $unboundChange->roleId, 'tableCode' => $unboundChange->tableCode],
			));
		}

		return $result;
	}

	/**
	 * Locks the roles before their cells are read, see RoleLocker.
	 *
	 * @param TableCellChange[] $changes
	 */
	private function findMissingRoleId(array $changes): ?int
	{
		return $this->roleLocker->lockAndFindMissing(array_map(
			static fn(TableCellChange $change): int => $change->roleId,
			$changes,
		));
	}

	/**
	 * Inheriting is a deletion and needs no group: only an override is checked against the bindings,
	 * which the caller has already written inside its transaction.
	 *
	 * @param TableCellChange[] $changes
	 */
	private function findOverrideOfUnboundTable(array $changes): ?TableCellChange
	{
		$overrides = array_values(array_filter(
			$changes,
			static fn(TableCellChange $change): bool => $change->rights !== null,
		));
		if (empty($overrides))
		{
			return null;
		}

		$tableCodes = array_values(array_unique(array_map(
			static fn(TableCellChange $change): string => $change->tableCode,
			$overrides,
		)));

		$boundCodes = [];
		$rows = TableGroupBindingTable::getList([
			'select' => ['TABLE_CODE'],
			'filter' => ['@TABLE_CODE' => $tableCodes],
		]);
		while ($row = $rows->fetch())
		{
			$boundCodes[(string)$row['TABLE_CODE']] = true;
		}

		foreach ($overrides as $change)
		{
			if (!isset($boundCodes[$change->tableCode]))
			{
				return $change;
			}
		}

		return null;
	}

	/**
	 * @param TableCellChange[] $changes Checked changes.
	 *
	 * @return bool Whether any row was deleted or inserted.
	 */
	private function write(array $changes): bool
	{
		if (empty($changes))
		{
			return false;
		}

		$storedValues = $this->fetchStoredValues($changes);

		$isChanged = false;
		$rows = [];
		$deletedIdsByRole = [];
		foreach ($changes as $change)
		{
			$permissionId = PermissionDictionary::getTableCellPermissionId($change->tableCode);
			$stored = $storedValues[$change->roleId][$permissionId] ?? [];
			$requested = $this->buildValues($change->rights);
			sort($stored);

			if ($stored === $requested)
			{
				continue;
			}

			if (!empty($stored))
			{
				$deletedIdsByRole[$change->roleId][$permissionId] = $permissionId;
			}

			foreach ($requested as $value)
			{
				$rows[] = [
					'ROLE_ID' => $change->roleId,
					'PERMISSION_ID' => $permissionId,
					'VALUE' => $value,
				];
			}

			$isChanged = true;
		}

		foreach ($deletedIdsByRole as $roleId => $permissionIds)
		{
			foreach (array_chunk(array_values($permissionIds), self::DELETE_CHUNK_SIZE) as $chunk)
			{
				PermissionTable::deleteList([
					'=ROLE_ID' => $roleId,
					'@PERMISSION_ID' => $chunk,
				]);
			}
		}

		RoleUtil::insertPermissions($rows);

		return $isChanged;
	}

	/**
	 * The read is a locking one: under REPEATABLE READ a plain read would answer from the snapshot of the save,
	 * taken before the roles were locked, and miss the rows another save committed meanwhile.
	 *
	 * @param TableCellChange[] $changes
	 *
	 * @return array<int, array<string, int[]>> Values of the stored rows by role and permission id.
	 */
	private function fetchStoredValues(array $changes): array
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$roleIds = [];
		$permissionIds = [];
		foreach ($changes as $change)
		{
			$roleIds[$change->roleId] = $change->roleId;
			$permissionId = PermissionDictionary::getTableCellPermissionId($change->tableCode);
			$permissionIds[$permissionId] = "'" . $helper->forSql($permissionId) . "'";
		}
		sort($roleIds);

		$rows = $connection->query(
			'SELECT ROLE_ID, PERMISSION_ID, VALUE FROM ' . PermissionTable::getTableName()
			. ' WHERE ROLE_ID IN (' . implode(', ', $roleIds) . ')'
			. ' AND PERMISSION_ID IN (' . implode(', ', $permissionIds) . ')'
			. ' ORDER BY ID FOR UPDATE'
		);

		$values = [];
		while ($row = $rows->fetch())
		{
			$values[(int)$row['ROLE_ID']][(string)$row['PERMISSION_ID']][] = (int)$row['VALUE'];
		}

		return $values;
	}

	/**
	 * @return int[] Values of the rows the cell is stored as, ascending; none for an inheriting cell.
	 */
	private function buildValues(?TableRights $rights): array
	{
		if ($rights === null)
		{
			return [];
		}

		if (!$rights->view)
		{
			return [self::NOTHING_ALLOWED_VALUE];
		}

		$values = [PermissionDictionary::BIC_TABLE_VIEW];
		if ($rights->edit)
		{
			$values[] = PermissionDictionary::BIC_TABLE_EDIT;
		}
		if ($rights->export)
		{
			$values[] = PermissionDictionary::BIC_TABLE_EXPORT;
		}

		return $values;
	}

	private function createInvalidError(?int $roleId, ?string $tableCode): UserFacingError
	{
		return new UserFacingError(
			(string)Loc::getMessage('BICONNECTOR_TABLE_CELL_ERROR_INVALID'),
			self::ERROR_INVALID,
			['roleId' => $roleId, 'tableCode' => $tableCode],
		);
	}
}
