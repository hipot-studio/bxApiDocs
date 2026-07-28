<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\Otp;

use Bitrix\Intranet\Internal\Integration\Security\OtpSettings;
use Bitrix\Main\Application;

class OtpBannerSessionDelay
{
	private const SESSION_KEY = 'otp_banner_type';
	private const LAST_CHECK_TIME_KEY = 'lastCheckTime';
	private const DEFAULT_DELAY_SECONDS = 900;

	public function __construct(
		private readonly int $delaySeconds = self::DEFAULT_DELAY_SECONDS,
	)
	{}

	public function postpone(): void
	{
		if (!(new OtpSettings())->isAvailable())
		{
			return;
		}

		Application::getInstance()
			->getLocalSession(self::SESSION_KEY)
			->set(self::LAST_CHECK_TIME_KEY, time())
		;
	}

	public function isPostponed(): bool
	{
		return (time() - $this->getLastCheckTime()) < $this->delaySeconds;
	}

	private function getLastCheckTime(): int
	{
		$sessionData = Application::getInstance()
			->getLocalSession(self::SESSION_KEY)
			->getData()
		;

		return (int)($sessionData[self::LAST_CHECK_TIME_KEY] ?? 0);
	}
}
