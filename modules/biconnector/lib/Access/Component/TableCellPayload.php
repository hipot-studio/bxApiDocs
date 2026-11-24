<?php

namespace Bitrix\BIConnector\Access\Component;

use Bitrix\BIConnector\Access\Permission\PermissionDictionary;
use Bitrix\BIConnector\Public\Command\TableCell\TableRights;
use Bitrix\BIConnector\Superset\Error\UserFacingError;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

/**
 * Turns the table rows of the saved matrix into the cells of the roles.
 *
 * A row of a table lives under each group of the table, while the cell is one for a role and a table:
 * the rows leave the rights of the role and become its `tableCells`, written by the save of the roles.
 */
final class TableCellPayload
{
	public const ERROR_CONFLICT = 'BICONNECTOR_TABLE_CELL_CONFLICT';

	/**
	 * @param array[] $userGroups Roles of the save request.
	 *
	 * @return Result Data: ['userGroups' => array[]] - the roles without table rows, a role that had them
	 * carries `tableCells` - code of the table to TableRights, null for a table that follows its groups.
	 */
	public function extract(array $userGroups): Result
	{
		$result = new Result();

		foreach ($userGroups as &$userGroup)
		{
			if (!is_array($userGroup))
			{
				continue;
			}

			// cells come only from the table rows the zoning of the save has let through, never from the request
			unset($userGroup['tableCells']);

			if (!isset($userGroup['accessRights']) || !is_array($userGroup['accessRights']))
			{
				continue;
			}

			$rowValues = [];
			$accessRights = [];
			foreach ($userGroup['accessRights'] as $accessRight)
			{
				$parsed = PermissionDictionary::parseTableRowPermissionId((string)($accessRight['id'] ?? ''));
				if ($parsed === null)
				{
					$accessRights[] = $accessRight;

					continue;
				}

				$rowValues[$parsed['tableCode']][(string)$accessRight['id']][] = (string)($accessRight['value'] ?? '');
			}

			if (empty($rowValues))
			{
				continue;
			}

			$tableCells = [];
			foreach ($rowValues as $tableCode => $rows)
			{
				$tableCode = (string)$tableCode;
				$values = $this->getAgreedValues($rows);
				$rights = $values === null ? false : $this->buildRights($values);
				if ($rights === false)
				{
					$result->addError($this->getConflictError($userGroup['id'] ?? null, $tableCode));

					continue;
				}

				$tableCells[$tableCode] = $rights;
			}

			$userGroup['accessRights'] = $accessRights;
			$userGroup['tableCells'] = $tableCells;
		}
		unset($userGroup);

		if ($result->isSuccess())
		{
			$result->setData(['userGroups' => $userGroups]);
		}

		return $result;
	}

	/**
	 * Descriptions of the table rows leave the rights of the request: they are rows of the screen, not
	 * permissions, and the save tells dashboard groups by a letter a table code may carry.
	 *
	 * @param array $accessRights
	 *
	 * @return array
	 */
	public function dropTableRows(array $accessRights): array
	{
		return array_values(array_filter(
			$accessRights,
			static fn($accessRight): bool => !(
				is_array($accessRight)
				&& PermissionDictionary::isTableRowPermission((string)($accessRight['id'] ?? ''))
			),
		));
	}

	/**
	 * @param array<string, string[]> $rows Id of a row of the table to its values.
	 *
	 * @return string[]|null Values every row of the table agrees on, null when they differ.
	 */
	private function getAgreedValues(array $rows): ?array
	{
		$agreed = null;
		foreach ($rows as $values)
		{
			$values = array_values(array_unique($values));
			sort($values, SORT_STRING);

			if ($agreed !== null && $agreed !== $values)
			{
				return null;
			}

			$agreed = $values;
		}

		return $agreed;
	}

	/**
	 * @param string[] $values
	 *
	 * @return TableRights|null|false null - the table follows its groups, false - the values make no state.
	 */
	private function buildRights(array $values): TableRights|null|false
	{
		$inheritValue = PermissionDictionary::getTableInheritValue();
		if (in_array($inheritValue, $values, true))
		{
			return count($values) === 1 ? null : false;
		}

		if (in_array('0', $values, true))
		{
			return count($values) === 1 ? new TableRights(false, false, false) : false;
		}

		$operations = array_map('strval', PermissionDictionary::getTablePermissionCodes());
		if (empty($values) || array_diff($values, $operations) !== [])
		{
			return false;
		}

		return new TableRights(
			in_array((string)PermissionDictionary::BIC_TABLE_VIEW, $values, true),
			in_array((string)PermissionDictionary::BIC_TABLE_EDIT, $values, true),
			in_array((string)PermissionDictionary::BIC_TABLE_EXPORT, $values, true),
		);
	}

	private function getConflictError(mixed $roleId, string $tableCode): UserFacingError
	{
		return new UserFacingError(
			// the screen shows the message as html, and the code comes from the request
			(string)Loc::getMessage(
				'BICONNECTOR_TABLE_CELL_PAYLOAD_CONFLICT',
				['#TABLE#' => htmlspecialcharsbx($tableCode)],
			),
			self::ERROR_CONFLICT,
			[
				'roleId' => $roleId,
				'tableCode' => $tableCode,
			],
		);
	}
}
