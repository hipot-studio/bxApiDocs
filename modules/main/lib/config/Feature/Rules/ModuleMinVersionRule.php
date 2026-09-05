<?php

namespace Bitrix\Main\Config\Feature\Rules;

use Bitrix\Main\Config\Feature\AbstractRule;
use Bitrix\Main\Config\Feature\Context;
use Bitrix\Main\ModuleManager;

final class ModuleMinVersionRule extends AbstractRule
{
	public function __construct(
		private readonly string $module,
		private readonly string $minVersion = '',
	)
	{
	}

	public static function createFromConfig(array $config = []): static
	{
		$module = $config['module'] ?? '';
		$minVersion = $config['minVersion'] ?? '';

		return new static((string)$module, (string)$minVersion);
	}

	public function check(Context $context): bool
	{
		$currentVersion = ModuleManager::getVersion($this->module);
		if (is_string($currentVersion) && $currentVersion !== '')
		{
			return version_compare($currentVersion, $this->minVersion, '>=');
		}

		return false;
	}
}
