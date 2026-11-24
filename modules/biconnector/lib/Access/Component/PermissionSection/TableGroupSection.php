<?php

namespace Bitrix\BIConnector\Access\Component\PermissionSection;

use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Component\PermissionConfig;
use Bitrix\BIConnector\Access\Permission\PermissionDictionary;
use Bitrix\BIConnector\Access\Service\TableCatalogService;
use Bitrix\BIConnector\Access\Service\TableGroupMembershipService;
use Bitrix\BIConnector\Configuration\Feature;

/**
 * Section of the table group rights: one row per group of tables, the rows of its tables under it.
 */
final class TableGroupSection extends BaseSection
{
	/**
	 * Prefix of a row whose group is not created yet.
	 */
	public const PLACEHOLDER_PREFIX = 'new_T';

	public function getCode(): string
	{
		return PermissionConfig::SECTION_TABLE_GROUP_RIGHTS;
	}

	public function canAddGroup(): bool
	{
		return true;
	}

	public function getRights(): array
	{
		$subtitles = PermissionDictionary::getTableGroupSubtitles();
		$tableTitles = null;
		$isValueClickable = Feature::isRowRulesEnabled();

		$rights = [];
		foreach (array_keys(PermissionDictionary::getTableGroupPermissions()) as $permissionId)
		{
			$right = PermissionDictionary::getPermission($permissionId);
			// The caption costs a read of the whole table catalog, so it is asked for here: the
			// slider is the only reader that shows it.
			$right['subtitle'] = $subtitles[$permissionId] ?? '';

			$groupId = PermissionDictionary::getTableGroupIdFromPermission((string)$permissionId);
			$tableCodes = TableGroupMembershipService::getTableCodesByGroupId($groupId);
			if (empty($tableCodes))
			{
				$rights[] = $right;

				continue;
			}

			$tableTitles ??= TableCatalogService::getTableTitles();
			$right['groupHead'] = true;
			$rights[] = $right;
			array_push($rights, ...$this->getTableRows($groupId, $tableCodes, $tableTitles, $isValueClickable));
		}

		return $rights;
	}

	/**
	 * @param int $groupId
	 * @param string[] $tableCodes
	 * @param array<string, string> $tableTitles
	 * @param bool $isValueClickable
	 *
	 * @return array[]
	 */
	private function getTableRows(int $groupId, array $tableCodes, array $tableTitles, bool $isValueClickable): array
	{
		$titles = [];
		foreach ($tableCodes as $tableCode)
		{
			$titles[$tableCode] = (string)($tableTitles[$tableCode] ?? $tableCode);
		}
		uksort(
			$titles,
			static fn($left, $right) => strnatcasecmp($titles[$left], $titles[$right]) ?: strcmp((string)$left, (string)$right),
		);

		$rows = [];
		foreach ($titles as $tableCode => $title)
		{
			$rows[] = PermissionDictionary::getTableRowPermission($groupId, (string)$tableCode, $title, $isValueClickable);
		}

		return $rows;
	}

	public function ownsPermissionId(int|string $permissionId): bool
	{
		if (!is_string($permissionId))
		{
			return false;
		}

		return str_starts_with($permissionId, self::PLACEHOLDER_PREFIX)
			|| PermissionDictionary::isTableGroupPermission($permissionId)
			|| PermissionDictionary::isTableRowPermission($permissionId)
		;
	}

	protected function getEditAction(): string
	{
		return ActionDictionary::ACTION_BIC_SETTINGS_EDIT_TABLE_RIGHTS;
	}
}
