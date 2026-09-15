<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Internal\Services\Config;

use Bitrix\Main\Config\Configuration;

/**
 * Config source: main (global) Configuration first, then module Configuration.
 * So values from /bitrix/.settings.php or .settings_extra.php override module's .settings.php.
 * Bitrix Configuration does not merge main and module for getInstance(moduleId) — it only reads
 * the module file. So "main overrides module" is implemented here explicitly.
 */
final class ConfigSource
{
	private const MODULE_ID = 'anonymizer';

	private Configuration $mainConfig;
	private Configuration $moduleConfig;

	public function __construct(
		?Configuration $mainConfig = null,
		?Configuration $moduleConfig = null,
	)
	{
		$this->mainConfig = $mainConfig ?? Configuration::getInstance();
		$this->moduleConfig = $moduleConfig ?? Configuration::getInstance(self::MODULE_ID);
	}

	/**
	 * Universal config lookup: main config first, then module config.
	 *
	 * If module config key is $name (e.g. proxy), then main config is checked by the prefixed name:
	 * "{moduleId}.{name}" (e.g. anonymizer.proxy).
	 */
	public function get(string $name): mixed
	{
		return
			$this->mainConfig->get(self::MODULE_ID . '.' . $name)
			?? $this->moduleConfig->get($name)
		;
	}
}
