<?php

declare(strict_types=1);

namespace Bitrix\Booking\Controller\V1\BookingService\Yandex\Filter;

use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Exception\Yandex\InternalErrorException;
use Bitrix\Booking\Internals\Service\ModuleOptions;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

class AccountRegistered extends Base
{
	public function onBeforeAction(Event $event): EventResult|null
	{
		if (!Container::getYandexAccount()->isRegistered())
		{
			$error = new InternalErrorException('Yandex account is not registered');
			$this->addError(
				new Error(
					$error->getMessage(),
					$error->getExternalCode(),
				),
			);

			return new EventResult(type: EventResult::ERROR, handler: $this);
		}

		if (!ModuleOptions::isRequestedFromYandex())
		{
			ModuleOptions::setRequestedFromYandex();
		}

		return null;
	}
}
