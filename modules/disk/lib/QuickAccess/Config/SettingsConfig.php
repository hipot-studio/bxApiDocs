<?php
declare(strict_types=1);

namespace Bitrix\Disk\QuickAccess\Config;

use Bitrix\Main\Config;

class SettingsConfig implements ConfigInterface
{
	private const CONFIG_SECTION = 'main.token_service';

	public function getKey(): ?string
	{
		return $this->getTokenService()[self::CONFIG_KEY] ?? null;
	}

	public function getTokenStorage(): array
	{
		return $this->getTokenService()[self::CONFIG_STORAGE] ?? [];
	}

	private function getTokenService(): ?array
	{
		return Config\Configuration::getValue(self::CONFIG_SECTION) ?: null;
	}

}
