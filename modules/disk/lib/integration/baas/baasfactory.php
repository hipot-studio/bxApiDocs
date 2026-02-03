<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\Baas;

use Bitrix\Baas\Baas;
use Bitrix\Main\Loader;

class BaasFactory
{
	private static ?Baas $instance = null;

	public static function getBaasInstance(): ?Baas
	{
		if (self::$instance !== null)
		{
			return self::$instance;
		}

		if (!Loader::includeModule('baas'))
		{
			return null;
		}

		$baas = Baas::getInstance();

		if ($baas->isAvailable() && $baas->isRegistered() && $baas->isActive())
		{
			self::$instance = $baas;
			return self::$instance;
		}

		return null;
	}
}