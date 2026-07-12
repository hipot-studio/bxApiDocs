<?php

declare(strict_types=1);

namespace Bitrix\SocialNetwork\Collab\Access\Rule;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Socialnetwork\Collab\Permission\UserRole;
use Bitrix\Socialnetwork\Permission\GroupAccessController;
use Bitrix\Socialnetwork\Permission\GroupDictionary;
use Bitrix\Socialnetwork\Permission\User\UserModel;
use Bitrix\SocialNetwork\Collab\Access\CollabAccessController;
use Bitrix\SocialNetwork\Collab\Access\CollabDictionary;
use Bitrix\SocialNetwork\Collab\Access\Model\CollabModel;
use Bitrix\SocialNetwork\Collab\Access\Rule\Trait\UserAccessCodeTrait;

class CollabExcludeRule extends AbstractRule
{
	use UserAccessCodeTrait;

	/** @var CollabAccessController */
	protected $controller;

	/** @var UserModel */
	protected $user;

	public function execute(AccessibleItem $item = null, $params = null): bool
	{
		if (!$item instanceof CollabModel)
		{
			$this->controller->addError(static::class, 'Wrong instance');

			return false;
		}

		$deleteMembers = $item->getDeleteMembers();

		$deleteMemberIds = [];

		$hasNonUserCodes = false;
		foreach ($deleteMembers as $accessCode)
		{
			$userId = $this->extractUserIdFromAccessCode($accessCode);
			if ($userId === null)
			{
				$hasNonUserCodes = true;

				continue;
			}

			$deleteMemberIds[] = $userId;
		}

		if (
			$hasNonUserCodes
			&& !$this->user->isAdmin()
			&& $this->user->getUserId() !== $item->getOwnerId()
		)
		{
			$this->controller->addError(static::class, 'Access denied for non-user access code');

			return false;
		}

		if (
			in_array($item->getOwnerId(), $deleteMemberIds, true)
			&& !$this->controller->check(CollabDictionary::LEAVE, $item, $params)
		)
		{
			$this->controller->addError(static::class, 'Access denied by owner role');

			return false;
		}

		$currentMembers = $item->getDomainObject()?->getMemberIdsWithRole();

		$members = [];
		foreach ($currentMembers as $userId => $role)
		{
			if (!in_array($userId, $deleteMemberIds, true))
			{
				continue;
			}

			$members[$role][] = $userId;
		}

		foreach ($members as $role => $userIds)
		{
			if (!$this->canExclude($item, $userIds, $role))
			{
				$this->controller->addError(static::class, 'Access denied by group controller');

				return false;
			}
		}

		if (empty($members) && !empty($deleteMemberIds))
		{
			$this->controller->addError(static::class, 'Access denied by members role');

			return false;
		}

		return true;
	}

	private function canExclude(CollabModel $item, array $userIds, string $targetRole): bool
	{
		$map = [
			UserRole::REQUEST => GroupDictionary::DELETE_OUTGOING_REQUEST,
			UserRole::MEMBER => GroupDictionary::EXCLUDE,
			UserRole::MODERATOR => GroupDictionary::REMOVE_MODERATOR,
		];

		$rule = $map[$targetRole] ?? GroupDictionary::EXCLUDE;

		foreach ($userIds as $userId)
		{
			if (
				!$this->controller->forward(
					GroupAccessController::class,
					$rule,
					$item,
					['userId' => $userId],
				)
			)
			{
				$this->controller->addError(static::class, 'Access denied by group controller');

				return false;
			}
		}

		return true;
	}
}
