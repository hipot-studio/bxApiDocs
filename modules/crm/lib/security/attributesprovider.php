<?php

namespace Bitrix\Crm\Security;

use Bitrix\Crm\Integration\HumanResources\DepartmentQueries;
use Bitrix\HumanResources\Type\AccessCodeType;
use Bitrix\Main\Loader;
use Bitrix\Crm\Integration\HumanResources\HumanResources;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\Security\Controller\QueryBuilder\RestrictionByAttributes\AttributesUtils;

class AttributesProvider
{
	protected $userId;
	protected ?array $userAttributes = null;
	protected $userAttributesCodes;
	protected $entityAttributes;

	private const CACHE_TIME = 8640000; // 100 days

	public function __construct(int $userId)
	{
		$this->userId = $userId;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function getUserAttributes(): array
	{
		if (!$this->userAttributes)
		{
			$this->userAttributes = $this->loadUserAttributes();
		}

		return $this->userAttributes;
	}

	public function getUserAttributesCodes(): array
	{
		if (!$this->userAttributesCodes)
		{
			$this->userAttributesCodes = $this->loadUserAccessCodes();
		}

		return $this->userAttributesCodes;
	}

	/**
	 * Returns attributes used to check entity permissions. Assumed that $this->userId contains ASSIGNED_BY_ID of this entity.
	 *
	 * @return string[]
	 */
	public function getEntityAttributes(): array
	{
		if (!$this->entityAttributes)
		{
			$this->entityAttributes = $this->loadEntityAttributes();
		}

		return $this->entityAttributes;
	}

	protected function loadUserAttributes(): array
	{
		$attributesByUser = [];

		Loader::requireModule('humanresources');

		$userAccessCodes = $this->getRawUserAccessCodes();
		$hrDepartmentsIds = [];
		$hrSubDepartmentsIds = [];
		$hrTeamsIds = [];
		$hrSubTeamsIds = [];
		foreach ($userAccessCodes as $accessCode)
		{
			if (AttributesUtils::tryParseHrDepartment($accessCode['ACCESS_CODE'], $nodeId))
			{
				$hrDepartmentsIds[] = $nodeId;
			}
			elseif (AttributesUtils::tryParseHrTeam($accessCode['ACCESS_CODE'], $nodeId))
			{
				$hrTeamsIds[] = $nodeId;
			}
			elseif (
				mb_strpos($accessCode['ACCESS_CODE'], 'DR') !== 0 // dr ignored!
				&& mb_strpos($accessCode['ACCESS_CODE'], AccessCodeType::HrDepartmentRecursiveType->value) !== 0 // dr ignored!
				&& mb_strpos($accessCode['ACCESS_CODE'], AccessCodeType::HrTeamRecursiveType->value) !== 0 // dr ignored!
			)
			{
				$attributesByUser[mb_strtoupper($accessCode['PROVIDER_ID'])][] = $accessCode['ACCESS_CODE'];
			}
		}

		if (\Bitrix\Crm\Security\Controller\Compatible::isAvailable() && !empty($attributesByUser['INTRANET']))
		{
			foreach ($attributesByUser['INTRANET'] as $iDepartment)
			{
				if (mb_substr($iDepartment, 0, 1) === 'D')
				{
					$departmentTree = $this->getIntranetSubDepartmentsIds((int)mb_substr($iDepartment, 1));
					foreach ($departmentTree as $departmentId)
					{
						$attributesByUser['SUBINTRANET'][] = 'D' . $departmentId;
					}
				}
			}
		}

		if (!empty($hrDepartmentsIds))
		{
			$hrSubDepartmentsIds = $this->getHrChildDepartmentNodesIds($hrDepartmentsIds);
		}

		if (!empty($hrTeamsIds))
		{
			$hrSubTeamsIds = $this->getHrChildTeamNodesIds($hrTeamsIds);
		}

		if (!empty($hrDepartmentsIds))
		{
			$attributesByUser['HR_DEPARTMENTS'] = $this->convertIdsToAccessCodes(
				AccessCodeType::HrDepartmentType,
				$hrDepartmentsIds,
			);
		}

		if (!empty($hrSubDepartmentsIds))
		{
			$attributesByUser['HR_SUBDEPARTMENTS'] = $this->convertIdsToAccessCodes(
				AccessCodeType::HrDepartmentType,
				$hrSubDepartmentsIds,
			);
		}

		if (!empty($hrTeamsIds))
		{
			$attributesByUser['HR_TEAMS'] = $this->convertIdsToAccessCodes(
				AccessCodeType::HrTeamType,
				$hrTeamsIds,
			);
		}

		if (!empty($hrSubTeamsIds))
		{
			$attributesByUser['HR_SUBTEAMS'] = $this->convertIdsToAccessCodes(
				AccessCodeType::HrTeamType,
				$hrSubTeamsIds,
			);
		}

		return $attributesByUser;
	}

	protected function loadEntityAttributes(): array
	{
		$userAttributes = $this->getUserAttributes();

		$attributes = array_merge(
			[
				UserPermissions::ATTRIBUTES_USER_PREFIX . $this->userId,
			],
			$userAttributes['HR_DEPARTMENTS'] ?? [],
			$userAttributes['HR_TEAMS'] ?? [],
		);

		if (\Bitrix\Crm\Security\Controller\Compatible::isAvailable())
		{
			$attributes = array_merge(
				$attributes,
				$userAttributes['INTRANET'] ?? [],
			);
		}

		return $attributes;
	}

	protected function getRawUserAccessCodes(): array
	{
		$userId = $this->getUserId();

		$cache = \Bitrix\Main\Application::getInstance()->getCache();

		$cacheId = 'crm_user_access_codes_' . $userId . '_' . md5(serialize($this->getUserAttributesCodes()));

		if ($cache->initCache(self::CACHE_TIME, $cacheId, '/crm/user_access_codes/'))
		{
			$result = $cache->getVars();
		}
		else
		{
			$cache->startDataCache();
			$result = [];
			$userAccessCodes = \CAccess::GetUserCodes($this->getUserId());
			while ($accessCode = $userAccessCodes->Fetch())
			{
				// imchat generates too much useless codes. Skip them:
				if ($accessCode['PROVIDER_ID'] !== 'imchat')
				{
					$result[] = $accessCode;
				}
			}
			$cache->endDataCache($result);
		}

		return $result;
	}

	protected function loadUserAccessCodes(): array
	{
		$userId = $this->getUserId();

		$access = new \CAccess();
		$access->UpdateCodes(['USER_ID' => $userId]);
		$userAccessCodes = $access->GetUserCodesArray($userId);

		$usefulUserAccessCodes = [];
		foreach ($userAccessCodes as $code)
		{
			if (mb_substr($code, 0, 4) !== 'CHAT') // code started from "CHAT" is useless
			{
				$usefulUserAccessCodes[] = $code;
			}
		}

		return $usefulUserAccessCodes;
	}

	protected function getIntranetSubDepartmentsIds($departmentId): array
	{
		return DepartmentQueries::getInstance()->getIntranetSubDepartmentsAccessCodesIds($departmentId);
	}

	protected function getHrChildDepartmentNodesIds(array $departmentIds): array
	{
		return DepartmentQueries::getInstance()->getHrChildNodesIds($departmentIds);
	}

	protected function getHrChildTeamNodesIds(array $teamIds): array
	{
		return DepartmentQueries::getInstance()->getHrChildTeamNodesIds($teamIds);
	}

	private function convertIdsToAccessCodes(AccessCodeType $accessCodeType, array $nodeIds): array
	{
		$humanResources = HumanResources::getInstance();
		$accessCodes = [];
		foreach ($nodeIds as $nodeId)
		{
			$accessCodes[] = $humanResources->buildAccessCode($accessCodeType->value, $nodeId);
		}

		return $accessCodes;
	}
}
