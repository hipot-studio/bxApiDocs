<?php

namespace Bitrix\Intranet\Infrastructure\Controller\ActionFilter;

use Bitrix\Main\Application;
use Bitrix\Main\Engine;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

class InvitationDeliveryLimit extends Engine\ActionFilter\Base
{
	private const MAX_BATCH_SIZE = 100;

	final public function onBeforeAction(Event $event): ?EventResult
	{
		$invitations = Application::getInstance()->getContext()->getRequest()->getPost('invitations');
		if (!is_array($invitations) || count($invitations) <= self::MAX_BATCH_SIZE)
		{
			return null;
		}

		$this->addError(new Error('', 'INVITATION_BATCH_LIMIT_EXCEEDED'));

		return new EventResult(EventResult::ERROR, null, null, $this);
	}
}
