<?php

namespace Bitrix\IntranetMobile\Controller;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

/**
 * Short-lived marketing campaign: the whole controller is dropped when the campaign ends.
 * The deadline is stored with an explicit timezone, so a portal in another timezone stops the
 * campaign at the same moment as a Moscow one.
 */
class CoworkMeetupPromo extends Base
{
	private const CAMPAIGN_END = '2026-09-30 11:00:00';
	private const CAMPAIGN_END_FORMAT = 'Y-m-d H:i:s';
	private const CAMPAIGN_TIMEZONE = 'Europe/Moscow';
	private const SUPPORTED_REGION = 'ru';

	public function canShowAction(): bool
	{
		return $this->isCampaignRunning()
			&& $this->isSupportedRegion()
			&& $this->isFreeLicense()
		;
	}

	private function isCampaignRunning(): bool
	{
		$end = new DateTime(
			self::CAMPAIGN_END,
			self::CAMPAIGN_END_FORMAT,
			new \DateTimeZone(self::CAMPAIGN_TIMEZONE),
		);

		return time() < $end->getTimestamp();
	}

	private function isSupportedRegion(): bool
	{
		return Application::getInstance()->getLicense()->getRegion() === self::SUPPORTED_REGION;
	}

	private function isFreeLicense(): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return false;
		}

		return \CBitrix24::isFreeLicense();
	}
}
