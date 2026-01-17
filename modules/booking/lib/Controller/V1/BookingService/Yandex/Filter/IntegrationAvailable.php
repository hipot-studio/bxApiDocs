<?php

declare(strict_types=1);

namespace Bitrix\Booking\Controller\V1\BookingService\Yandex\Filter;

use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Exception\Yandex\InternalErrorException;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

class IntegrationAvailable extends Base
{
	public function onBeforeAction(Event $event): EventResult|null
	{
		if (!Container::getYandexAvailabilityService()->isAvailable())
		{
			$error = new InternalErrorException('Integration is not available');
			$this->addError(
				new Error(
					$error->getMessage(),
					$error->getExternalCode(),
				),
			);

			return new EventResult(type: EventResult::ERROR, handler: $this);
		}

		return null;
	}
}
