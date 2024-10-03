<?php

namespace Bitrix\Im\V2\Entity\User;

use Bitrix\Im\V2\Chat\ChatError;
use Bitrix\Im\V2\Result;

class UserExtranet extends User
{
	protected function checkAccessInternal(User $otherUser): Result
	{
		$result = new Result();

		if (!static::$moduleManager::isModuleInstalled('intranet'))
		{
			if (!$this->hasAccessBySocialNetwork($otherUser->getId()))
			{
				$result->addError(new ChatError(ChatError::ACCESS_DENIED));
			}

			return $result;
		}

		if ($otherUser->isBot())
		{
			return $result;
		}

		if ($this->isNetwork() && !$otherUser->isExtranet())
		{
			return $result;
		}

		$inGroup = \Bitrix\Im\Integration\Socialnetwork\Extranet::isUserInGroup($this->getId(), $otherUser->getId());
		if ($inGroup)
		{
			return $result;
		}

		return $result->addError(new ChatError(ChatError::ACCESS_DENIED));
	}
}