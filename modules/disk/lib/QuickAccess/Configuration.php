<?php

declare(strict_types=1);

namespace Bitrix\Disk\QuickAccess;

use Bitrix\Disk\QuickAccess\Config\ConfigInterface;
use Bitrix\Disk\QuickAccess\Config\JsonConfig;
use Bitrix\Disk\QuickAccess\Config\SettingsConfig;

final class Configuration implements ConfigInterface
{
	private ConfigInterface $config;

	public function __construct(JsonConfig $jsonConfig, SettingsConfig $settingsConfig)
	{
		$this->config = $jsonConfig->isset() ? $jsonConfig : $settingsConfig;
	}

	public function getKey(): ?string
	{
		return $this->config->getKey();
	}

	public function getTokenStorage(): array
	{
		return $this->config->getTokenStorage();
	}
}