<?php

declare(strict_types=1);

namespace Bitrix\Crm\Tour;

use Bitrix\Crm\Integration\NotificationsManager;
use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;

final class NotificationsForcedSms extends Base
{
	protected const OPTION_NAME = 'notifications-forced-sms';

	protected function canShow(): bool
	{
		if (!$this->isShowEnabled())
		{
			return false;
		}

		if ($this->isUserSeenTour())
		{
			return false;
		}

		return true;
	}

	protected function getShowDeadline(): ?DateTime
	{
		return new DateTime('01.04.2026', 'd.m.Y');
	}

	protected function getSteps(): array
	{
		return [
			[
				'id' => self::OPTION_NAME,
				'title' => Loc::getMessage('CRM_TOUR_NOTIFICATIONS_FORCED_SMS_TITLE'),
				'text' => Loc::getMessage('CRM_TOUR_NOTIFICATIONS_FORCED_SMS_TEXT'),
				'position' => 'top',
				'useDynamicTarget' => true,
				'eventName' => 'BX.Crm.Timeline.Notification:onShowForcedSmsTour',
				'article' => 18114500,
			],
		];
	}

	protected function getOptions(): array
	{
		return [
			'hideTourOnMissClick' => true,
			'steps' => [
				'popup' => [
					'width' => 400,
				],
			],
		];
	}

	protected function isShowEnabled(): bool
	{
		return NotificationsManager::canUse() && Application::getInstance()->getLicense()->getRegion() === 'ru';
	}
}
