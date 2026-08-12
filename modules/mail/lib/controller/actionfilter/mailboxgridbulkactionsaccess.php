<?php

declare(strict_types=1);

namespace Bitrix\Mail\Controller\ActionFilter;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

final class MailboxGridBulkActionsAccess extends Base
{
	public const ERROR_FEATURE_DISABLED = 'MAILBOX_GRID_BULK_ACTIONS_DISABLED';

	public function onBeforeAction(Event $event)
	{
		if (!Feature::isMailboxGridBulkActionsAvailable())
		{
			Context::getCurrent()->getResponse()->setStatus(403);
			$this->addError(new Error(
				'Mailbox grid bulk actions are not available',
				self::ERROR_FEATURE_DISABLED,
			));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}
}
