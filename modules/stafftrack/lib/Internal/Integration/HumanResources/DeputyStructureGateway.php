<?php

namespace Bitrix\StaffTrack\Internal\Integration\HumanResources;

use Bitrix\HumanResources\Builder\Structure\Filter\Column\IdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeTypeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\RoleFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeMemberFilter;
use Bitrix\HumanResources\Builder\Structure\NodeMemberDataBuilder;
use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Public\Service\Container as HrPublicContainer;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\NodeMemberRole;
use Bitrix\Main\Loader;

class DeputyStructureGateway
{
	/**
	 * @return int[]
	 */
	public function getDepartmentIdsWhereUserIsDeputy(int $userId): array
	{
		if ($userId <= 0 || !Loader::includeModule('humanresources'))
		{
			return [];
		}

		try
		{
			return IdNormalizer::normalize(
				HrPublicContainer::getUserDepartmentService()->getDepartmentIdsWhereUserIsDeputy($userId),
			);
		}
		catch (\Throwable $e)
		{
			self::logException($e, 'getDepartmentIdsWhereUserIsDeputy');

			return [];
		}
	}

	/**
	 * @param int[] $departmentIds
	 * @param NodeMemberRole[] $roles
	 * @return int[]
	 */
	public function getMemberIds(array $departmentIds, array $roles, DepthLevel $depthLevel): array
	{
		$departmentIds = IdNormalizer::normalize($departmentIds);
		if (empty($departmentIds) || !Loader::includeModule('humanresources'))
		{
			return [];
		}

		try
		{
			$members = NodeMemberDataBuilder::createWithFilter(
				new NodeMemberFilter(
					entityType: MemberEntityType::USER,
					nodeFilter: new NodeFilter(
						idFilter: IdFilter::fromIds($departmentIds),
						entityTypeFilter: NodeTypeFilter::fromNodeType(NodeEntityType::DEPARTMENT),
						depthLevel: $depthLevel,
					),
					roleFilter: empty($roles) ? null : RoleFilter::fromRoles(...$roles),
				),
			)
				->getAll()
				->getEntityIds()
			;
		}
		catch (\Throwable $e)
		{
			self::logException($e, 'getMemberIds');

			return [];
		}

		return IdNormalizer::normalize($members);
	}

	private static function logException(\Throwable $e, string $method): void
	{
		AddMessage2Log(
			'DeputyStructureGateway::' . $method . ' error: ' . $e->getMessage(),
			'stafftrack',
		);
	}
}
