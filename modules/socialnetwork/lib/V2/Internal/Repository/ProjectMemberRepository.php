<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;


use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\Provider\GroupProvider;
use Bitrix\Socialnetwork\UserToGroupTable;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityCollection;
use Bitrix\Socialnetwork\V2\Internal\Entity\User\Role;
use Bitrix\Socialnetwork\V2\Internal\Entity\Workgroup\WorkgroupUserRelation;
use Bitrix\Socialnetwork\V2\Internal\Integration\HumanResources\Service\StructureRelationService;

class ProjectMemberRepository implements ProjectMemberRepositoryInterface
{
	public function __construct(
		private readonly StructureRelationService $structureRelationService,
	)
	{
	}

	public function getMemberCodes(int $groupId): array
	{
		$rows = UserToGroupTable::getList([
			'filter' => [
				'=GROUP_ID' => $groupId,
				'@ROLE' => UserToGroupTable::getRolesMember(),
				// New structure-initiated members have INITIATED_BY_TYPE='S' and legacy members have AUTO_MEMBER='Y'
				'!=INITIATED_BY_TYPE' => UserToGroupTable::INITIATED_BY_STRUCTURE,
				'!=AUTO_MEMBER' => 'Y',
			],
			'select' => ['USER_ID'],
		])->fetchAll();

		$codes = array_map(
			static fn(array $row): string => 'U' . $row['USER_ID'],
			$rows,
		);

		foreach ($this->structureRelationService->getLinkedDepartmentAccessCodes($groupId) as $accessCode)
		{
			$codes[] = $accessCode;
		}

		return $codes;
	}

	public function getOwnerUserId(int $groupId): ?int
	{
		$row = UserToGroupTable::getList([
			'filter' => [
				'=GROUP_ID' => $groupId,
				'=ROLE' => UserToGroupTable::ROLE_OWNER,
			],
			'select' => ['USER_ID'],
			'limit' => 1,
		])->fetch();

		$userId = (int)($row['USER_ID'] ?? 0);

		return $userId > 0 ? $userId : null;
	}

	public function getModeratorCodes(int $groupId): array
	{
		$rows = UserToGroupTable::getList([
			'filter' => [
				'=GROUP_ID' => $groupId,
				'=ROLE' => UserToGroupTable::ROLE_MODERATOR,
			],
			'select' => ['USER_ID'],
		])->fetchAll();

		return array_map(
			static fn(array $row): string => 'U' . $row['USER_ID'],
			$rows,
		);
	}

	public function isCollabExists(int $groupId): bool
	{
		return GroupProvider::getInstance()->getGroupType($groupId) === Type::Collab;
	}

	public function getModeratorUserIds(int $groupId): array
	{
		$rows = UserToGroupTable::query()
			->setDistinct()
			->setSelect(['USER_ID'])
			->where('GROUP_ID', $groupId)
			->where('ROLE', '<=', UserToGroupTable::ROLE_MODERATOR)
			->exec()
			->fetchAll();

		return array_map(
			static fn(array $row): int => (int)$row['USER_ID'],
			$rows,
		);
	}

	public function getMemberUserIds(int $groupId): array
	{
		$rows = UserToGroupTable::getList([
			'filter' => [
				'=GROUP_ID' => $groupId,
				'@ROLE' => UserToGroupTable::getRolesMember(),
			],
			'select' => ['USER_ID'],
		])->fetchAll();

		return array_map(
			static fn(array $row): int => (int)$row['USER_ID'],
			$rows,
		);
	}

	public function getInvitedUserIds(int $projectId, array $userIds): MemberEntityCollection
	{
		$rows =
			UserToGroupTable::getList([
				'filter' => [
					'=GROUP_ID' => $projectId,
					'=USER_ID' => $userIds,
					'=ROLE' => UserToGroupTable::ROLE_REQUEST,
					'=INITIATED_BY_TYPE' => UserToGroupTable::INITIATED_BY_GROUP,
				],
				'select' => ['USER_ID'],
			])
				->fetchAll()
		;

		return MemberEntityCollection::mapFromIds(array_map('intval', array_column($rows, 'USER_ID')));
	}

	public function getMemberRoles(array $groupIds, int $userId): array
	{
		if (empty($groupIds) || $userId <= 0)
		{
			return [];
		}

		$rows = UserToGroupTable::getList([
			'filter' => [
				'=USER_ID' => $userId,
				'=GROUP_ID' => $groupIds,
				'@ROLE' => UserToGroupTable::getRolesMember(),
			],
			'select' => ['GROUP_ID', 'ROLE'],
		])->fetchAll();

		$result = [];
		foreach ($rows as $row)
		{
			$result[(int)$row['GROUP_ID']] = $row['ROLE'];
		}

		return $result;
	}

	public function getUserRelations(array $groupIds, int $userId): array
	{
		if (empty($groupIds) || $userId <= 0)
		{
			return [];
		}

		$rows = UserToGroupTable::getList([
			'filter' => [
				'=USER_ID' => $userId,
				'=GROUP_ID' => $groupIds,
			],
			'select' => [
				'GROUP_ID',
				'USER_ID',
				'ROLE',
				'INITIATED_BY_TYPE',
				'INITIATED_BY_USER_ID',
				'AUTO_MEMBER',
			],
		])->fetchAll();

		$result = [];
		foreach ($rows as $row)
		{
			$role = Role::tryFrom((string)($row['ROLE'] ?? ''));
			if ($role === null)
			{
				continue;
			}

			$groupId = (int)$row['GROUP_ID'];
			$initiatedByUserId = (int)($row['INITIATED_BY_USER_ID'] ?? 0);

			$result[$groupId] = new WorkgroupUserRelation(
				groupId: $groupId,
				userId: (int)$row['USER_ID'],
				role: $role,
				initiatedByType: is_string($row['INITIATED_BY_TYPE'] ?? null)
					? $row['INITIATED_BY_TYPE']
					: null,
				initiatedByUserId: $initiatedByUserId > 0 ? $initiatedByUserId : null,
				autoMember: ($row['AUTO_MEMBER'] ?? 'N') === 'Y',
			);
		}

		return $result;
	}

	public function getOutgoingRequestFlags(array $groupIds, int $userId): array
	{
		if (empty($groupIds) || $userId <= 0)
		{
			return [];
		}

		$rows = UserToGroupTable::getList([
			'filter' => [
				'=USER_ID' => $userId,
				'=GROUP_ID' => $groupIds,
				'=ROLE' => UserToGroupTable::ROLE_REQUEST,
				'=INITIATED_BY_TYPE' => UserToGroupTable::INITIATED_BY_USER,
			],
			'select' => ['GROUP_ID'],
		])->fetchAll();

		$result = [];
		foreach ($rows as $row)
		{
			$result[(int)$row['GROUP_ID']] = true;
		}

		return $result;
	}

	public function getIncomingInviteFlags(array $groupIds, int $userId): array
	{
		if (empty($groupIds) || $userId <= 0)
		{
			return [];
		}

		$queryResult = UserToGroupTable::query()
			->setSelect(['GROUP_ID'])
			->where('USER_ID', $userId)
			->whereIn('GROUP_ID', $groupIds)
			->where('ROLE', UserToGroupTable::ROLE_REQUEST)
			->where('INITIATED_BY_TYPE', UserToGroupTable::INITIATED_BY_GROUP)
			->exec()
		;

		$result = [];
		while ($row = $queryResult->fetch())
		{
			$result[(int)$row['GROUP_ID']] = true;
		}

		return $result;
	}

	public function getStructureInitiatedMemberIds(int $projectId, array $userIds): array
	{
		if (empty($userIds))
		{
			return [];
		}

		$rows = UserToGroupTable::query()
			->setSelect(['USER_ID'])
			->where('GROUP_ID', $projectId)
			->whereIn('USER_ID', $userIds)
			->where('INITIATED_BY_TYPE', UserToGroupTable::INITIATED_BY_STRUCTURE)
			->exec()
			->fetchAll()
		;

		return array_map(
			static fn(array $row): int => (int)$row['USER_ID'],
			$rows,
		);
	}

	public function markMembersAsStructureInitiated(int $projectId, array $userIds): array
	{
		if (empty($userIds))
		{
			return [];
		}

		$rows =
			UserToGroupTable::query()
				->setSelect(['ID', 'USER_ID'])
				->where('GROUP_ID', $projectId)
				->whereIn('USER_ID', $userIds)
				->where('ROLE', UserToGroupTable::ROLE_USER)
				->where('INITIATED_BY_TYPE', '!=', UserToGroupTable::INITIATED_BY_STRUCTURE)
				->exec()
				->fetchAll()
		;

		if (empty($rows))
		{
			return [];
		}

		$ids = array_map(static fn(array $row): int => (int)$row['ID'], $rows);

		UserToGroupTable::updateMulti($ids, [
			'INITIATED_BY_TYPE' => UserToGroupTable::INITIATED_BY_STRUCTURE,
			'AUTO_MEMBER' => 'Y',
		]);

		return array_map(static fn(array $row): int => (int)$row['USER_ID'], $rows);
	}
}
