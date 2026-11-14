<?php

namespace Bitrix\StaffTrack\Internal\Integration\HumanResources;

use Bitrix\HumanResources\Builder\Structure\Filter\Column\EntityIdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\IdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeTypeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\RoleFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeMemberFilter;
use Bitrix\HumanResources\Builder\Structure\NodeMemberDataBuilder;
use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Enum\NodeActiveFilter;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\NodeMemberRole;
use Bitrix\Main\Loader;

/**
 * The memberships Node\UserService::isManagerForEmployee() answers from, read the way its query reads them:
 * active or not, in departments active or not, virtual users included. The defaults of the builder would
 * narrow each of the three.
 */
class ManagedEmployeeGateway
{
	/**
	 * @return int[] the departments themselves, without their subtrees
	 */
	public function getDepartmentIdsByRole(int $userId, NodeMemberRole $role): array
	{
		if ($userId <= 0 || !Loader::includeModule('humanresources'))
		{
			return [];
		}

		try
		{
			return IdNormalizer::normalize($this->loadDepartmentIdsByRole($userId, $role));
		}
		catch (\Throwable $e)
		{
			self::logException($e, 'getDepartmentIdsByRole');

			return [];
		}
	}

	/**
	 * @param int[] $userIds
	 * @param int[] $departmentIds
	 * @param NodeMemberRole[] $roles
	 * @return int[] those of $userIds holding one of $roles in one of $departmentIds or in a department below it
	 */
	public function getMemberIdsWithin(array $userIds, array $departmentIds, array $roles): array
	{
		$userIds = IdNormalizer::normalize($userIds);
		$departmentIds = IdNormalizer::normalize($departmentIds);
		if (empty($userIds) || empty($departmentIds) || empty($roles) || !Loader::includeModule('humanresources'))
		{
			return [];
		}

		try
		{
			return IdNormalizer::normalize($this->loadMemberIdsWithin($userIds, $departmentIds, $roles));
		}
		catch (\Throwable $e)
		{
			self::logException($e, 'getMemberIdsWithin');

			return [];
		}
	}

	/**
	 * @return int[]
	 */
	protected function loadDepartmentIdsByRole(int $userId, NodeMemberRole $role): array
	{
		$rows = NodeMemberDataBuilder::createWithFilter(
			new NodeMemberFilter(
				entityIdFilter: EntityIdFilter::fromEntityId($userId),
				entityType: MemberEntityType::USER,
				nodeFilter: new NodeFilter(
					entityTypeFilter: NodeTypeFilter::fromNodeType(NodeEntityType::DEPARTMENT),
					active: NodeActiveFilter::ALL,
				),
				active: null,
				roleFilter: RoleFilter::fromRole($role),
				withVirtualUsers: true,
			),
		)
			->prepareQuery()
			->setSelect(['NODE_ID'])
			->setDistinct()
			->fetchAll()
		;

		return array_map(static fn (array $row): int => (int)$row['NODE_ID'], $rows);
	}

	/**
	 * @param int[] $userIds
	 * @param int[] $departmentIds
	 * @param NodeMemberRole[] $roles
	 * @return int[]
	 */
	protected function loadMemberIdsWithin(array $userIds, array $departmentIds, array $roles): array
	{
		$rows = NodeMemberDataBuilder::createWithFilter(
			new NodeMemberFilter(
				entityIdFilter: EntityIdFilter::fromEntityIds($userIds),
				entityType: MemberEntityType::USER,
				nodeFilter: new NodeFilter(
					idFilter: IdFilter::fromIds($departmentIds),
					entityTypeFilter: NodeTypeFilter::fromNodeType(NodeEntityType::DEPARTMENT),
					depthLevel: DepthLevel::FULL,
					active: NodeActiveFilter::ALL,
				),
				active: null,
				roleFilter: RoleFilter::fromRoles(...$roles),
				withVirtualUsers: true,
			),
		)
			->prepareQuery()
			->setSelect(['ENTITY_ID'])
			->setDistinct()
			->fetchAll()
		;

		return array_map(static fn (array $row): int => (int)$row['ENTITY_ID'], $rows);
	}

	private static function logException(\Throwable $e, string $method): void
	{
		AddMessage2Log(
			'ManagedEmployeeGateway::' . $method . ' error: ' . $e->getMessage(),
			'stafftrack',
		);
	}
}
