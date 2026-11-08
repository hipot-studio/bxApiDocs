<?php

namespace Bitrix\HumanResources\Service\Access;

use Bitrix\HumanResources\Access\Role\RoleUtil;
use Bitrix\HumanResources\Service\Container;
use Bitrix\Main\Access\AccessCode;
use Bitrix\Main\Access\Exception\RoleRelationSaveException;

class RoleRelationService
{
	private const INVALID_SETTINGS_ERROR = 'Invalid role relation settings format.';

	/**
	 * @throws \DomainException
	 */
	public function assertSettingsShape(array $settings): void
	{
		foreach ($settings as $setting)
		{
			if (!is_array($setting))
			{
				continue;
			}

			$accessCodes = $setting['accessCodes'] ?? null;
			if ($accessCodes === null)
			{
				continue;
			}

			if (!is_array($accessCodes))
			{
				throw new \DomainException(self::INVALID_SETTINGS_ERROR);
			}

			// updateRoleRelations() wipes the whole relation set of the role before it validates the codes.
			foreach (array_keys($accessCodes) as $code)
			{
				if (!AccessCode::isValid((string)$code))
				{
					throw new \DomainException(self::INVALID_SETTINGS_ERROR);
				}
			}
		}
	}

	/**
	 * Malformed entries are skipped instead of aborting the whole save: a garbage element must not
	 * create relations and must not wipe the relations of a role it does not describe.
	 *
	 * @throws RoleRelationSaveException
	 */
	public function saveRoleRelation(array $settings): void
	{
		foreach ($settings as $setting)
		{
			if (!is_array($setting) || !isset($setting['id']) || !is_scalar($setting['id']))
			{
				continue;
			}

			$roleId = (int)$setting['id'];
			if ($roleId <= 0)
			{
				continue;
			}

			$accessCodes = $setting['accessCodes'] ?? [];
			if (!is_array($accessCodes))
			{
				continue;
			}

			(new RoleUtil($roleId))->updateRoleRelations($accessCodes);
		}
	}

	public function deleteRelationsByRoleId(int $roleId): void
	{
		Container::getAccessRoleRelationRepository()->deleteRelationsByRoleId($roleId);
	}

	/**
	 * @param array<int> $roleId
	 */
	public function deleteRelationsByRoleIds(array $roleIds): void
	{
		$roleIds = array_values(array_unique(array_filter(array_map('intval', $roleIds))));
		if (empty($roleIds))
		{
			return;
		}

		Container::getAccessRoleRelationRepository()->deleteRelationsByRoleIds($roleIds);
	}

	public function getRelationList(array $parameters = []): array
	{
		return Container::getAccessRoleRelationRepository()->getRelationList($parameters);
	}
}
