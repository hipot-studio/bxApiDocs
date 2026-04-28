<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Infrastructure\Controller\ActionFilter;

use Bitrix\Intranet\Internal\Integration\Bitrix24\PortalCreatorService;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Localization\Loc;

class PortalCreatorEmailConfirmationControl extends Base
{
	public function onBeforeAction(Event $event): ?EventResult
	{
		if (!(new PortalCreatorService())->isPortalCreatorEmailConfirmed())
		{
			$this->addError(new Error(Loc::getMessage('INTRANET_INVITATION_ACCESS_CONTROL_CREATORS_EMAIL_IS_NOT_CONFIRMED')));

			return new EventResult(EventResult::ERROR, null, 'intranet', $this);
		}

		return null;
	}
}