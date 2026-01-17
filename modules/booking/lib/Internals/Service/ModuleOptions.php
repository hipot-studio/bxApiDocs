<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service;

use Bitrix\Main\Config\Option;

class ModuleOptions
{
	public const FIRST_RESOURCE_ADDED_OPTION = 'first_resource_added';
	private const AUTOCONFIRM_PERIOD_OPTION = 'auto_confirm_period';
	private const REQUESTED_FROM_YANDEX = 'requested_from_yandex';
	private const MODULE_ID = 'booking';

	public static function handleResourceAdded(): void
	{
		if (self::isFirstResourceAdded())
		{
			return;
		}

		self::setFirstResourceAdded();
	}

	public static function getDefaultAutoConfirmPeriodMinutes(): int
	{
		return (int)Option::get(moduleId: self::MODULE_ID, name: self::AUTOCONFIRM_PERIOD_OPTION, default: (24 * 60));
	}

	public static function isRequestedFromYandex(): bool
	{
		return Option::get(
			moduleId: self::MODULE_ID,
			name: self::REQUESTED_FROM_YANDEX,
			default: 'N',
		) === 'Y';
	}

	public static function setRequestedFromYandex(bool $value = true): void
	{
		Option::set(
			moduleId: self::MODULE_ID,
			name: self::REQUESTED_FROM_YANDEX,
			value: $value ? 'Y' : 'N',
		);
	}

	public static function deleteRequestedFromYandex(): void
	{
		Option::delete(
			moduleId: self::MODULE_ID,
			filter: ['name' => self::REQUESTED_FROM_YANDEX],
		);
	}

	private static function isFirstResourceAdded(): bool
	{
		return (bool)Option::get(moduleId: self::MODULE_ID, name: self::FIRST_RESOURCE_ADDED_OPTION, default: false);
	}

	private static function setFirstResourceAdded(): void
	{
		Option::set(moduleId: self::MODULE_ID, name: self::FIRST_RESOURCE_ADDED_OPTION, value: true);
	}
}
