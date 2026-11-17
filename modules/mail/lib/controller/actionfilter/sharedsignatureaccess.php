<?php

declare(strict_types=1);

namespace Bitrix\Mail\Controller\ActionFilter;

use Bitrix\Mail\Helper\MailAccess;
use Bitrix\Mail\Service\SharedSignature\SharedSignatureService;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

final class SharedSignatureAccess extends Base
{
	public const ERROR_ACCESS_DENIED = SharedSignatureService::ERROR_ACCESS_DENIED;

	public function onBeforeAction(Event $event)
	{
		if (!MailAccess::hasCurrentUserAccessToSharedSignatureManagement())
		{
			Context::getCurrent()->getResponse()->setStatus(403);
			$this->addError(SharedSignatureService::accessDeniedError());

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}
}

