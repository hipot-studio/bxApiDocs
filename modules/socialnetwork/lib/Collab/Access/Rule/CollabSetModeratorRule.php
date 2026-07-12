<?php

declare(strict_types=1);

namespace Bitrix\SocialNetwork\Collab\Access\Rule;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Socialnetwork\Permission\GroupAccessController;
use Bitrix\Socialnetwork\Permission\GroupDictionary;
use Bitrix\SocialNetwork\Collab\Access\CollabAccessController;
use Bitrix\SocialNetwork\Collab\Access\Model\CollabModel;
use Bitrix\SocialNetwork\Collab\Access\Rule\Trait\UserAccessCodeTrait;

class CollabSetModeratorRule extends AbstractRule
{
	use UserAccessCodeTrait;

	/** @var CollabAccessController */
	protected $controller;

	public function execute(AccessibleItem $item = null, $params = null): bool
	{
		if (!$item instanceof CollabModel)
		{
			$this->controller->addError(static::class, 'Wrong instance');

			return false;
		}

		$setModerators = $item->getAddModeratorMembers();
		foreach ($setModerators as $accessCode)
		{
			$userId = $this->extractUserIdFromAccessCode($accessCode);
			if ($userId === null)
			{
				$this->controller->addError(static::class, 'Access denied by non-user access code');

				return false;
			}

			if (
				!$this->controller->forward(
					GroupAccessController::class,
					GroupDictionary::UPDATE,
					$item,
					['userId' => $userId]
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
