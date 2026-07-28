<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Vibecodeconnector;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Vibecodeconnector\Internal\Service\Provisioning\PermissionSource\Policy;
use Bitrix\Vibecodeconnector\Internal\Service\Provisioning\PermissionSource\Settings;

class PermissionSource
{
	private bool $available;
	private ?Policy $policy = null;
	private ?Settings $settings = null;

	public function __construct()
	{
		$this->available =
			Loader::includeModule('vibecodeconnector')
			&& class_exists(Policy::class)
			&& class_exists(Settings::class)
		;

		if ($this->available)
		{
			$this->policy = ServiceLocator::getInstance()->get(Policy::class);
			$this->settings = ServiceLocator::getInstance()->get(Settings::class);
		}
	}

	public function isAvailable(): bool
	{
		return $this->available;
	}

	public function isVibecodeSource(): bool
	{
		return $this->policy?->isVibecodeSource() ?? false;
	}

	public function setVibecodeSource(bool $isVibecode): void
	{
		$this->settings?->setVibecodeSource($isVibecode);
	}
}
