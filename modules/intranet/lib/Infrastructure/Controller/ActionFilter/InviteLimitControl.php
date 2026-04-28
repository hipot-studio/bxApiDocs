<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Infrastructure\Controller\ActionFilter;

use Bitrix\Intranet\Internal\Integration\Bitrix24\License\InvitationLimiter;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Localization\Loc;

class InviteLimitControl extends Base
{
	public function onBeforeAction(Event $event): ?EventResult
	{
		if ((new InvitationLimiter())->isExceeded())
		{
			$this->addError(new Error(Loc::getMessage('INTRANET_INVITATION_ACCESS_CONTROL_TOO_MANY_INVITATIONS')));

			return new EventResult(EventResult::ERROR, null, 'bitrix24', $this);
		}

		return null;
	}
}
