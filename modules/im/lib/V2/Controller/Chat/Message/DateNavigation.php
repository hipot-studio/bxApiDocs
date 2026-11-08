<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Controller\Chat\Message;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\Message\DateNavigation\DateNavigationError;
use Bitrix\Im\V2\Message\DateNavigation\DateNavigationService;

class DateNavigation extends BaseController
{
	/**
	 * @restMethod im.v2.Chat.Message.DateNavigation.getCalendar
	 */
	public function getCalendarAction(
		Chat $chat,
		string $from,
		string $to,
		?int $timezoneOffset = null,
		?string $timezoneId = null,
	): ?array
	{
		if (!$this->checkAvailability())
		{
			return null;
		}

		$fromDate = DateNavigationService::parseDate($from);
		$toDate = DateNavigationService::parseDate($to);
		if ($fromDate === null || $toDate === null)
		{
			$this->addError(new DateNavigationError(DateNavigationError::WRONG_DATE_FORMAT));

			return null;
		}

		$service = $this->getService($chat, $timezoneId, $timezoneOffset);
		if ($service === null)
		{
			return null;
		}

		$result = $service->getCalendar($fromDate, $toDate);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $this->toRestFormat($result->getResult());
	}

	/**
	 * @restMethod im.v2.Chat.Message.DateNavigation.resolveDate
	 */
	public function resolveDateAction(
		Chat $chat,
		string $date,
		?int $timezoneOffset = null,
		?string $timezoneId = null,
	): ?array
	{
		if (!$this->checkAvailability())
		{
			return null;
		}

		$targetDate = DateNavigationService::parseDate($date);
		if ($targetDate === null)
		{
			$this->addError(new DateNavigationError(DateNavigationError::WRONG_DATE_FORMAT));

			return null;
		}

		$service = $this->getService($chat, $timezoneId, $timezoneOffset);
		if ($service === null)
		{
			return null;
		}

		$result = $service->resolveDate($targetDate);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $this->toRestFormat($result->getResult());
	}

	private function getService(Chat $chat, ?string $timezoneId, ?int $timezoneOffset): ?DateNavigationService
	{
		$service = DateNavigationService::createForClient($chat, $timezoneId, $timezoneOffset);

		if ($service === null)
		{
			$this->addError(new DateNavigationError(DateNavigationError::WRONG_TIMEZONE));
		}

		return $service;
	}

	private function checkAvailability(): bool
	{
		if (!Features::isMessageDateNavigationAvailable())
		{
			$this->addError(new DateNavigationError(DateNavigationError::NOT_AVAILABLE));

			return false;
		}

		return true;
	}
}
