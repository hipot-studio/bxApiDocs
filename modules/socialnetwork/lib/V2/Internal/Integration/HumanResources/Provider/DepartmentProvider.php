<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\HumanResources\Provider;

use Bitrix\HumanResources\Enum\NodeActiveFilter;
use Bitrix\HumanResources\Public\Service\Container as HumanResourcesContainer;
use Bitrix\HumanResources\Public\Service\NodeService;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntity;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityType;
use Bitrix\Socialnetwork\V2\Internal\Repository\Mapper\MemberEntityMapper;
use Throwable;

class DepartmentProvider
{
	public function __construct(
		private readonly MemberEntityMapper $memberEntityMapper,
	)
	{
	}

	/**
	 * @param array<int|string, mixed> $legacyDepartmentIds
	 * @return array<int, string>
	 */
	public function getNamesByLegacyIds(array $legacyDepartmentIds): array
	{
		$legacyDepartmentIds = $this->normalizeLegacyDepartmentIds($legacyDepartmentIds);
		if ($legacyDepartmentIds === [] || !$this->isHumanResourcesModuleAvailable())
		{
			return [];
		}

		$accessCodes = [];
		foreach ($legacyDepartmentIds as $departmentId)
		{
			$accessCode = $this->memberEntityMapper->toAccessCode(new MemberEntity(
				id: $departmentId,
				type: MemberEntityType::Department,
				withChildNodes: true,
			));
			if ($accessCode !== null)
			{
				$accessCodes[] = $accessCode;
			}
		}

		try
		{
			$nodes = $this->getNodeService()->findAllByAccessCodes(
				accessCodes: $accessCodes,
				activeFilter: NodeActiveFilter::ALL,
			);
		}
		catch (Throwable)
		{
			return [];
		}

		$requestedDepartmentIds = array_fill_keys($legacyDepartmentIds, true);
		$namesByDepartmentId = [];
		foreach ($nodes as $node)
		{
			if ($node->accessCode === null || $node->name === null)
			{
				continue;
			}

			$department = $this->memberEntityMapper->fromAccessCode($node->accessCode);
			if (
				$department?->type !== MemberEntityType::Department
				|| $department->id === null
				|| !isset($requestedDepartmentIds[$department->id])
			)
			{
				continue;
			}

			$namesByDepartmentId[$department->id] = $node->name;
		}

		return $namesByDepartmentId;
	}

	protected function isHumanResourcesModuleAvailable(): bool
	{
		return Loader::includeModule('humanresources');
	}

	protected function getNodeService(): NodeService
	{
		return HumanResourcesContainer::getNodeService();
	}

	/**
	 * @param array<int|string, mixed> $legacyDepartmentIds
	 * @return int[]
	 */
	private function normalizeLegacyDepartmentIds(array $legacyDepartmentIds): array
	{
		$normalizedIds = [];
		foreach ($legacyDepartmentIds as $departmentId)
		{
			if (is_string($departmentId) && preg_match('/^-?\d+$/D', $departmentId) === 1)
			{
				$departmentId = (int)$departmentId;
			}

			if (!is_int($departmentId) || $departmentId <= 0)
			{
				continue;
			}

			$normalizedIds[$departmentId] = $departmentId;
		}

		return array_values($normalizedIds);
	}
}
