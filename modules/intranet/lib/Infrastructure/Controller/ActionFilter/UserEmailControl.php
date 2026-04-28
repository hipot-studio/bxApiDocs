<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Infrastructure\Controller\ActionFilter;

use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Localization\Loc;

class UserEmailControl extends Base
{
	public function __construct(
		private readonly string $email,
	)
	{
		parent::__construct();
	}

	public function onBeforeAction(Event $event): ?EventResult
	{
		if (empty($this->email) || !check_email($this->email))
		{
			$this->addError(new Error(Loc::getMessage('INTRANET_USER_EMAIL_CONTROL_ERROR_INVALID_FORMAT')));

			return new EventResult(EventResult::ERROR, null, 'bitrix24', $this);
		}

		return null;
	}
}