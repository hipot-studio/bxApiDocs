<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Trait;

use Bitrix\Tasks\Access\Role\RoleDictionary;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;

trait MemberTrait
{
	private const FIELD_CREATED_BY = 'CREATED_BY';
	private const FIELD_RESPONSIBLE_ID = 'RESPONSIBLE_ID';
	private const FIELD_ACCOMPLICES = 'ACCOMPLICES';
	private const FIELD_AUDITORS = 'AUDITORS';

	private const FIELD_MEMBER_MAP = [
		RoleDictionary::ROLE_DIRECTOR => self::FIELD_CREATED_BY,
		RoleDictionary::ROLE_RESPONSIBLE => self::FIELD_RESPONSIBLE_ID,
		RoleDictionary::ROLE_ACCOMPLICE => self::FIELD_ACCOMPLICES,
		RoleDictionary::ROLE_AUDITOR => self::FIELD_AUDITORS,
	];

	private function makeMemberCollectionForSave(
		array $innerFields,
		?UserCollection $taskMembers,
		?Task $task,
	): ?UserCollection
	{
		$memberRoleMap = [];
		if ($taskMembers)
		{
			foreach ($taskMembers as $member)
			{
				$memberRoleMap[$member->role][] = $member;
			}
		}

		$repaired = $task && $this->repairMembersIfNeed($memberRoleMap, $task);
		$hasChanged = $this->prepareMembers($innerFields, $memberRoleMap);

		if ($repaired === false && $hasChanged === false)
		{
			// no change
			return null;
		}

		$toSaveCollection = new UserCollection();
		foreach ($memberRoleMap as $userRoleList)
		{
			foreach ($userRoleList as $user)
			{
				$toSaveCollection->add($user);
			}
		}

		return $toSaveCollection;
	}

	private function repairMembersIfNeed(array &$memberRoleMap, Task $task): bool
	{
		$changedResponsible = $this->updateRoleMember(
			$task->responsible?->id,
			$memberRoleMap,
			RoleDictionary::ROLE_RESPONSIBLE,
		);

		$changedOriginator = $this->updateRoleMember(
			$task->creator?->id,
			$memberRoleMap,
			RoleDictionary::ROLE_DIRECTOR,
		);

		return $changedResponsible || $changedOriginator;
	}

	private function updateRoleMember(?int $userId, array &$memberRoleMap, string $role): bool
	{
		if (array_key_exists($role, $memberRoleMap) || !$userId)
		{
			return false;
		}

		$memberRoleMap[$role] = [];
		$this->addUserToRole($memberRoleMap, $userId, $role);

		return true;
	}

	private function addUserToRole(array &$memberRoleMap, int $userId, string $role): void
	{
		$memberRoleMap[$role][] = new User(
			id: $userId,
			role: $role,
		);
	}

	private function prepareMembers(array $data, array &$memberRoleMap): bool
	{
		$originatorChanged = $this->replaceUser($memberRoleMap, $data, RoleDictionary::ROLE_DIRECTOR);
		$responsibleChanged = $this->replaceUser($memberRoleMap, $data, RoleDictionary::ROLE_RESPONSIBLE);
		$accomplicesChanged = $this->replaceUserList($memberRoleMap, $data, RoleDictionary::ROLE_ACCOMPLICE);
		$auditorsChanged = $this->replaceUserList($memberRoleMap, $data, RoleDictionary::ROLE_AUDITOR);

		return $originatorChanged
			|| $responsibleChanged
			|| $accomplicesChanged
			|| $auditorsChanged
		;
	}

	private function replaceUser(array &$members, ?array $innerData, string $role): bool
	{
		$field = self::FIELD_MEMBER_MAP[$role] ?? '';
		if (!$field || !array_key_exists($field, $innerData))
		{
			return false;
		}

		$value = $innerData[$field];
		if (!is_numeric($value))
		{
			return false;
		}

		$userId = (int)$value;
		if ($userId < 1)
		{
			return false;
		}

		$members[$role] = [];
		$this->addUserToRole($members, $userId, $role);

		return true;
	}

	private function replaceUserList(array &$members, ?array $innerData, string $role): bool
	{
		$field = self::FIELD_MEMBER_MAP[$role] ?? '';
		if (!$field || !array_key_exists($field, $innerData))
		{
			return false;
		}

		$userIdList = $innerData[$field];
		if (!is_array($userIdList))
		{
			return false;
		}

		$alreadyAdded = [];
		$members[$role] = [];
		foreach ($userIdList as $userId)
		{
			if (!is_numeric($userId))
			{
				continue;
			}

			$userId = (int)$userId;
			if ($userId < 1 || array_key_exists($userId, $alreadyAdded))
			{
				continue;
			}

			$alreadyAdded[$userId] = true;
			$this->addUserToRole($members, $userId, $role);
		}

		return true;
	}
}
