<?php

declare(strict_types=1);

namespace Bitrix\Mail\Controller\ActionFilter;

use Bitrix\Mail\Helper\Mailbox\MailboxConnectionRequestService;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

final class ConnectionRequestResponsibleAdminAccess extends Base
{
	public const ERROR_ACCESS_DENIED = 'MAIL_CONNECTION_REQUEST_RESPONSIBLE_ADMIN_ACCESS_DENIED';

	public function onBeforeAction(Event $event)
	{
		$service = new MailboxConnectionRequestService();

		if (!$service->isResponsibleAdmin())
		{
			Context::getCurrent()->getResponse()->setStatus(403);
			$this->addError(new Error('Access denied', self::ERROR_ACCESS_DENIED));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}
}
