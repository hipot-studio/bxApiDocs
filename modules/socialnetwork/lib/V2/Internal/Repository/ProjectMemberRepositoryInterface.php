<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;

use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityCollection;
use Bitrix\Socialnetwork\V2\Internal\Entity\Workgroup\WorkgroupUserRelation;

interface ProjectMemberRepositoryInterface
{
	/** @return string[] */
	public function getMemberCodes(int $groupId): array;

	public function getOwnerUserId(int $groupId): ?int;

	/** @return string[] */
	public function getModeratorCodes(int $groupId): array;

	public function isCollabExists(int $groupId): bool;

	/**
	 * @param int $projectId
	 * @param int[] $userIds
	 * @return int[]
	 */
	public function getStructureInitiatedMemberIds(int $projectId, array $userIds): array;

	/**
	 * @param int $projectId
	 * @param int[] $userIds
	 * @return int[]
	 */
	public function markMembersAsStructureInitiated(int $projectId, array $userIds): array;

	/**
	 * @return int[]
	 */
	public function getModeratorUserIds(int $groupId): array;

	/**
	 * @return int[]
	 */
	public function getMemberUserIds(int $groupId): array;

	/**
	 * @param int $projectId
	 * @param int[] $userIds
	 */
	public function getInvitedUserIds(int $projectId, array $userIds): MemberEntityCollection;

	/**
	 * @param int[] $groupIds
	 * @return array<int, string> groupId => role (A/E/K) for groups where user is a member
	 */
	public function getMemberRoles(array $groupIds, int $userId): array;

	/**
	 * @param int[] $groupIds
	 * @return array<int, WorkgroupUserRelation>
	 */
	public function getUserRelations(array $groupIds, int $userId): array;

	/**
	 * @param int[] $groupIds
	 * @return array<int, true> groupId => true for groups where user has a pending request initiated by themselves
	 */
	public function getOutgoingRequestFlags(array $groupIds, int $userId): array;

	/**
	 * @param int[] $groupIds
	 * @return array<int, true> groupId => true for groups where user has a pending invite initiated by group
	 */
	public function getIncomingInviteFlags(array $groupIds, int $userId): array;
}
