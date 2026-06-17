<?php

declare(strict_types=1);

namespace Bitrix\Mail\Controller\ActionFilter;

use Bitrix\Mail\Helper\LicenseManager;
use Bitrix\Mail\Helper\MailAccess;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

final class MassConnectAccess extends Base
{
	public const ERROR_LICENSE_DENIED = 'MASS_CONNECT_LICENSE_DENIED';
	public const ERROR_ACCESS_DENIED = 'MASS_CONNECT_ACCESS_DENIED';

	public function onBeforeAction(Event $event)
	{
		if (!LicenseManager::isMailboxesMassConnectEnabled())
		{
			Context::getCurrent()->getResponse()->setStatus(403);
			$this->addError(new Error(
				'Mass mailbox connection is not allowed',
				self::ERROR_LICENSE_DENIED,
			));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		if (!MailAccess::hasCurrentUserAccessToMassConnect())
		{
			Context::getCurrent()->getResponse()->setStatus(403);
			$this->addError(new Error(
				'Access denied',
				self::ERROR_ACCESS_DENIED,
			));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}
}
