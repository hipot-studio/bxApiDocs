<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Control;

use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Socialnetwork\Collab\Control\Command\CollabUpdateCommand;
use Bitrix\Socialnetwork\Control\GroupResult;
use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Socialnetwork\Provider\CollabUserPolicyProvider;
use Bitrix\Socialnetwork\UserToGroupTable;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityType;
use Bitrix\Socialnetwork\V2\Internal\Repository\Mapper\MemberEntityMapper;
use Bitrix\Socialnetwork\WorkgroupTable;

class ExternalMembershipValidator
{
	public function validate(CollabUpdateCommand $command, Workgroup $before): GroupResult
	{
		if (Loader::includeModule('extranet') || !$before->isCollab())
		{
			return new GroupResult();
		}

		return $this->validateCommand($command, $before->getId());
	}

	private function validateCommand(CollabUpdateCommand $command, int $groupId): GroupResult
	{
		$delta = [];
		$mapper = Container::getInstance()->get(MemberEntityMapper::class);
		$membersByRole = [
			UserToGroupTable::ROLE_USER => [
				...($command->getAddMembers() ?? []),
				...($command->getAddInvitedMembers() ?? []),
			],
			UserToGroupTable::ROLE_MODERATOR => $command->getAddModeratorMembers() ?? [],
		];
		foreach ($membersByRole as $role => $codes)
		{
			foreach ($codes as $code)
			{
				$member = is_string($code) && preg_match('/^(U|D|DR|SNT|SNTR)[0-9]+$/D', $code) === 1
					? $mapper->fromAccessCode($code)
					: null;
				if ($member === null)
				{
					return self::denied();
				}
				if ($member->type === MemberEntityType::User)
				{
					$delta[$member->id] = $role;

					continue;
				}

				if (!Loader::includeModule('humanresources'))
				{
					return self::denied();
				}
				$node = \Bitrix\HumanResources\Public\Service\Container::getNodeService()->getByAccessCode($code);
				if ($node === null)
				{
					return self::denied();
				}

			}
		}
		$ownerId = $command->getOwnerId();
		if ($ownerId !== null)
		{
			$delta[$ownerId] = UserToGroupTable::ROLE_OWNER;
		}

		return $this->validateDelta($groupId, $delta);
	}

	/** @param array<int, string> $userRoleDelta */
	public function validateDelta(int $groupId, array $userRoleDelta): GroupResult
	{
		return self::getRejectedUserIds($groupId, $userRoleDelta) === [] ? new GroupResult() : self::denied();
	}

	public static function filterUsers(
		int $groupId,
		array $userIds,
		string $role = UserToGroupTable::ROLE_USER,
	): array
	{
		$delta = array_fill_keys(array_map('intval', $userIds), $role);

		return array_values(array_diff($userIds, self::getRejectedUserIds($groupId, $delta)));
	}

	/** @param array<int, string> $userRoleDelta */
	private static function getRejectedUserIds(int $groupId, array $userRoleDelta): array
	{
		if (Loader::includeModule('extranet') || $userRoleDelta === [])
		{
			return [];
		}

		$group = WorkgroupTable::query()->setSelect(['TYPE', 'OWNER_ID'])->where('ID', $groupId)->fetch();
		if (($group['TYPE'] ?? null) !== Workgroup\Type::Collab->value)
		{
			return [];
		}

		$roles = [];
		$relations = UserToGroupTable::query()
			->setSelect(['USER_ID', 'ROLE'])
			->where('GROUP_ID', $groupId)
			->whereIn('USER_ID', array_keys($userRoleDelta))
			->exec()
		;
		while ($relation = $relations->fetch())
		{
			$roles[(int)$relation['USER_ID']] = $relation['ROLE'];
		}

		$ownerId = (int)($group['OWNER_ID'] ?? 0);
		if ($ownerId > 0)
		{
			$roles[$ownerId] = UserToGroupTable::ROLE_OWNER;
		}

		$newUserIds = [];
		foreach ($userRoleDelta as $userId => $role)
		{
			$previous = $roles[(int)$userId] ?? null;
			if ($previous === $role)
			{
				continue;
			}
			$wasMember = in_array($previous, UserToGroupTable::getRolesMember(), true);
			$isMember = in_array($role, UserToGroupTable::getRolesMember(), true);
			$isPromotion = $wasMember && $isMember && strcmp($role, $previous) < 0;
			if ((!$wasMember && ($isMember || $role === UserToGroupTable::ROLE_REQUEST)) || $isPromotion)
			{
				$newUserIds[] = (int)$userId;
			}
		}
		$classification = CollabUserPolicyProvider::getInstance()->classify($newUserIds);

		return array_values(array_diff($newUserIds, $classification['internal']));
	}

	public static function validateUsers(
		int $groupId,
		array $userIds,
		string $role = UserToGroupTable::ROLE_USER,
	): GroupResult
	{
		$delta = [];
		foreach ($userIds as $userId)
		{
			$delta[(int)$userId] = $role;
		}

		return (new self())->validateDelta($groupId, $delta);
	}

	public static function checkUsers(int $groupId, array $userIds, string $role = UserToGroupTable::ROLE_USER): bool
	{
		$result = self::validateUsers($groupId, $userIds, $role);
		if (!$result->isSuccess())
		{
			$GLOBALS['APPLICATION']->ThrowException(implode('; ', $result->getErrorMessages()));
		}

		return $result->isSuccess();
	}

	public static function checkRelations(int $groupId, array $relationIds, string $role): bool
	{
		if (Loader::includeModule('extranet') || !self::isCollab($groupId) || $relationIds === [])
		{
			return true;
		}
		$rows = UserToGroupTable::query()
			->setSelect(['USER_ID'])
			->where('GROUP_ID', $groupId)
			->whereIn('ID', array_map('intval', $relationIds))
			->exec()->fetchAll()
		;

		return self::checkUsers($groupId, array_column($rows, 'USER_ID'), $role);
	}

	public static function validateFields(
		int $groupId,
		array $fields,
		array $userIds = [],
		?array $beforeFields = null,
	): GroupResult
	{
		$membershipKeys = ['UF_SG_DEPT', 'USER_CODES', 'USER_IDS', 'MODERATOR_CODES', 'OWNER_ID', 'EMAILS', 'EMAIL'];
		if ($userIds === [] && array_intersect($membershipKeys, array_keys($fields)) === [])
		{
			return new GroupResult();
		}
		if (Loader::includeModule('extranet') || $groupId <= 0)
		{
			return new GroupResult();
		}

		$hasDepartments = array_key_exists('UF_SG_DEPT', $fields);
		if (
			$beforeFields === null
			|| !array_key_exists('TYPE', $beforeFields)
			|| ($hasDepartments && !array_key_exists('UF_SG_DEPT', $beforeFields))
		)
		{
			$select = $hasDepartments ? ['TYPE', 'UF_SG_DEPT'] : ['TYPE'];
			$beforeFields = WorkgroupTable::query()->setSelect($select)->where('ID', $groupId)->fetch();
		}
		if (($beforeFields['TYPE'] ?? null) !== Workgroup\Type::Collab->value)
		{
			return new GroupResult();
		}

		$codes = [];
		$addedDepartmentIds = array_diff(
			self::getPositiveIds((array)($fields['UF_SG_DEPT'] ?? [])),
			self::getPositiveIds((array)($beforeFields['UF_SG_DEPT'] ?? [])),
		);
		foreach ($addedDepartmentIds as $departmentId)
		{
			$codes[] = 'DR' . $departmentId;
		}
		foreach ((array)($fields['USER_CODES'] ?? []) as $code)
		{
			if ($code !== '')
			{
				$codes[] = $code;
			}
		}
		foreach (self::getPositiveIds([...$userIds, ...(array)($fields['USER_IDS'] ?? [])]) as $userId)
		{
			$codes[] = 'U' . (int)$userId;
		}
		$command = (new CollabUpdateCommand())
			->setId($groupId)
			->setAddMembers($codes)
			->setAddModeratorMembers(array_values(array_filter((array)($fields['MODERATOR_CODES'] ?? []))))
		;
		if (!empty($fields['OWNER_ID']))
		{
			$command->setOwnerId((int)$fields['OWNER_ID']);
		}
		$contacts = preg_split('/[\s,;]+/', (string)($fields['EMAILS'] ?? '') . ' ' . ($fields['EMAIL'] ?? ''));
		$contacts = array_values(array_filter(array_unique($contacts)));
		if ($contacts !== [])
		{
			$users = \Bitrix\Main\UserTable::query()
				->setSelect(['ID', 'EMAIL'])
				->whereIn('EMAIL', $contacts)
				->exec()
			;
			$foundContacts = [];
			$contactUserIds = [];
			while ($user = $users->fetch())
			{
				$codes[] = 'U' . (int)$user['ID'];
				$contactUserIds[] = (int)$user['ID'];
				$foundContacts[] = mb_strtolower($user['EMAIL']);
			}
			if (array_diff(array_map('mb_strtolower', $contacts), $foundContacts) !== [])
			{
				return self::denied();
			}
			$classification = CollabUserPolicyProvider::getInstance()->classify($contactUserIds);
			if ($classification['external'] !== [] || $classification['missing'] !== [])
			{
				return self::denied();
			}
			$command->setAddMembers($codes);
		}

		return (new self())->validateCommand($command, $groupId);
	}

	private static function getPositiveIds(array $ids): array
	{
		return array_values(array_filter(array_unique(array_map('intval', $ids)), static fn(int $id): bool => $id > 0));
	}

	public static function isCollab(int $groupId): bool
	{
		if ($groupId <= 0)
		{
			return false;
		}
		$row = WorkgroupTable::query()->setSelect(['TYPE'])->where('ID', $groupId)->fetch();

		return ($row['TYPE'] ?? null) === Workgroup\Type::Collab->value;
	}

	public static function denied(): GroupResult
	{
		Loc::loadMessages(dirname(__DIR__) . '/Requirement.php');

		return (new GroupResult())->addError(new Error(Loc::getMessage(
			'SOCIALNETWORK_COLLAB_REQUIREMENT_UNINSTALLED_MODULES',
			['#MODULES#' => 'extranet'],
		)));
	}
}
