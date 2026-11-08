<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Support;

use Bitrix\Catalog\Product\Sku;
use Bitrix\Main\Application;

/**
 * V3 copy of Engine Product\SkuDeferredCalculations (without Engine Action dependency).
 */
final class SkuDeferredCalculations
{
	private static bool $isBackgroundJobAdded = false;

	public static function enable(): void
	{
		Sku::enableDeferredCalculation();
	}

	public static function disableAndSchedule(): void
	{
		Sku::disableDeferredCalculation();
		self::addBackgroundJob();
	}

	private static function addBackgroundJob(): void
	{
		if (self::$isBackgroundJobAdded)
		{
			return;
		}

		self::$isBackgroundJobAdded = true;

		Application::getInstance()->addBackgroundJob(Sku::calculate(...));
	}
}
