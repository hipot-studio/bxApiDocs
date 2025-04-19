<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Onboarding;

use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Config\Option;
use Bitrix\Tasks\Onboarding\Internal\Agent\CommandAgent;
use Bitrix\Tasks\Onboarding\Internal\Agent\GarbageCollectorAgent;

final class OnboardingFeature
{
	public static function isOn(): bool
	{
		return Option::get('tasks', 'tasks_onboarding_feature', 'Y') === 'Y';
	}

	public static function turnOn(): void
	{
		Option::set('tasks', 'tasks_onboarding_feature', 'Y');

		CommandAgent::install();
		GarbageCollectorAgent::install();
	}

	public static function isAvailable(): bool
	{
		if (self::isDevMode())
		{
			return true;
		}

		if (!self::isOn())
		{
			return false;
		}

		return true;
	}

	public static function isNewPortal(): bool
	{
		return Portal::getInstance()->isNew();
	}

	private static function isDevMode(): bool
	{
		$exceptionHandling = Configuration::getValue('exception_handling');

		return !empty($exceptionHandling['debug']);
	}
}