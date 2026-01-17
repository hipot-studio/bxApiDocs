<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Yandex;

use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Service\Logger\EventLogger;
use Bitrix\Booking\Internals\Service\Logger\EventTypeEnum;
use Bitrix\Booking\Internals\Service\Logger\LogLevelEnum;

class CompanyFeedSenderAgent
{
	private const MODULE_ID = 'booking';

	public static function execute(): string|null
	{
		$account = Container::getYandexAccount();
		if (!$account->isRegistered())
		{
			return null;
		}

		$companyCollection = Container::getYandexCompanyFeedProvider()->getCompanies();
		$companyFeedSender = Container::getYandexCompanyFeedSender();
		$companyFeedHashService = Container::getYandexCompanyFeedHashService();

		// feed has not changed since the last time
		if ($companyFeedHashService->getCurrent() === $companyFeedHashService->calculate($companyCollection))
		{
			return self::getName();
		}

		$result = $companyFeedSender->sendFeed($companyCollection);
		if (!$result->isSuccess())
		{
			(new EventLogger())->log(
				LogLevelEnum::Error,
				implode(';', $result->getErrorMessages()),
				EventTypeEnum::YandexCompanyFeedSender
			);
		}

		return self::getName();
	}

	public static function install(): void
	{
		\CAgent::AddAgent(
			name: self::getName(),
			module: self::MODULE_ID,
			interval: 60 * 60 * 12, // 12 hours,
			next_exec: ConvertTimeStamp(time() + \CTimeZone::GetOffset() + 600, 'FULL'),
			existError: false
		);
	}

	public static function uninstall(): void
	{
		\CAgent::RemoveAgent(name: self::getName(), module: self::MODULE_ID);
	}

	public static function rescheduleForNow(): void
	{
		$agent = \CAgent::GetList(
			[],
			[
				'MODULE_ID' => self::MODULE_ID,
				'=NAME' => self::getName(),
			]
		)->fetch();

		$agentId = isset($agent['ID']) ? (int)$agent['ID'] : null;
		if (!$agentId)
		{
			return;
		}

		\CAgent::Update(
			$agentId,
			[
				'NEXT_EXEC' =>  \ConvertTimeStamp(time() + \CTimeZone::GetOffset(), 'FULL'),
			]
		);
	}

	private static function getName(): string
	{
		return '\\' . static::class . '::execute();';
	}
}
