<?php

namespace Bitrix\Im\V2\Controller\Filter;

use Bitrix\Im\V2\Chat\ChatError;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

class ExternalUserTypeFilter extends Base
{
	private array $skipTypes;

	public function __construct(array $skipTypes = [])
	{
		parent::__construct();
		$this->skipTypes = $skipTypes;
	}

	public function onBeforeAction(Event $event)
	{
		$userId = (int)$this->getAction()->getCurrentUser()?->getId();
		$user = User::getInstance($userId);

		if (!$user->isInternalType($this->skipTypes))
		{
			$this->addError(new ChatError(ChatError::ACCESS_DENIED));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}
}
