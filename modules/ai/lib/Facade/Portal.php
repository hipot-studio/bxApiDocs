<?php

declare(strict_types=1);

namespace Bitrix\AI\Facade;

use Bitrix\AI\Integration\Baas\BaasTokenService;
use Bitrix\AI\Tuning\Defaults;
use Bitrix\AI\Tuning\Manager;
use Bitrix\Bitrix24\License;
use Bitrix\Bitrix24\Public\Enum\VibePlus\MonetizationModel;
use Bitrix\Bitrix24\Public\Service\VibePlus\RuntimeStateProvider;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;
use Bitrix\Main\Web\Json;
use CBitrix24;
use Psr\Log\NullLogger;

class Portal
{
	private const DEFAULT_REGION = 'en';

	private const CACHE_ID = 'bx_ai_portal_creation_time';
	private const CACHE_DIR = '/bx/ai/portal';

	private const VIBE_PLUS_LICENSE_TYPE_SUFFIX = '_vibe';
	private const DEMO_LICENSE_TYPE = 'demo';
	private const VIBE_PLUS_LOGGER_ID = 'ai.vibe_plus.runtime_state';

	private const MODULE_AI_ID = 'ai';
	private const MODULE_AIASSISTANT_ID = 'aiassistant';
	private const OPTION_TUNING = 'tuning';
	private const AIASSISTANT_SCENARIO_OPTIONS = [
		'remote_mcp_server_active',
		'remote_mcp_server_sharing_only',
		'enable_bitrix_internal_mcp_server',
	];

	private static bool $missingRuntimeStateProviderWarningLogged = false;
	private static ?bool $enabledAiScenarioResult = null;
	private static ?string $enabledAiScenarioKey = null;

	public static function getCreationDateTime(): ?DateTime
	{
		if (Loader::includeModule('bitrix24'))
		{
			$timestamp = (int)CBitrix24::getCreateTime();

			return $timestamp > 0 ? DateTime::createFromTimestamp($timestamp) : null;
		}

		$cache = Cache::createInstance();

		if ($cache->initCache(31536000, self::CACHE_ID, self::CACHE_DIR))
		{
			$timestamp = (int)($cache->getVars() ?? 0);

			return $timestamp > 0 ? DateTime::createFromTimestamp($timestamp) : null;
		}

		if (!$cache->startDataCache())
		{
			return null;
		}

		$firstUser = UserTable::query()
			->setSelect(['ID', 'DATE_REGISTER'])
			->where('ID', 1)
			->setLimit(1)
			->fetchObject()
		;

		$timestamp = (int)($firstUser?->getDateRegister()?->getTimestamp() ?: 0);

		if ($timestamp <= 0)
		{
			$cache->abortDataCache();

			return null;
		}

		$cache->endDataCache($timestamp);

		return DateTime::createFromTimestamp($timestamp);
	}

	public static function isWestZone(): bool
	{
		if (Loader::includeModule('bitrix24'))
		{
			return Bitrix24::isWestZone();
		}

		if (Loader::includeModule('intranet'))
		{
			return Intranet::isWestZone();
		}

		return false;
	}

	public static function getRegion(): string
	{
		return Application::getInstance()->getLicense()->getRegion() ?? self::DEFAULT_REGION;
	}

	public static function isMarketAvailable(): bool
	{
		return ServiceLocator::getInstance()->get(BaasTokenService::class)?->isMarketAvailable() ?? false;
	}

	public static function isBitrix24Portal(): bool
	{
		return ModuleManager::isModuleInstalled('bitrix24');
	}

	/**
	 * Returns true while at least one AI scenario is enabled in the portal settings.
	 */
	public static function hasEnabledAiScenario(): bool
	{
		// Building Tuning\Manager fires the ai:onTuningLoad handlers of every module, so
		// repeat calls inside one hit reuse the result. It is not cached across requests:
		// handlers may hinge a scenario on their own module's options, and there is no
		// way to enumerate all of those for a cache key.
		$key = self::getAiScenarioStateKey();
		if (self::$enabledAiScenarioKey === $key)
		{
			return self::$enabledAiScenarioResult;
		}

		$result = self::detectEnabledAiScenario();
		self::$enabledAiScenarioKey = $key;

		return self::$enabledAiScenarioResult = $result;
	}

	private static function getAiScenarioStateKey(): string
	{
		$key = (string)Option::get(self::MODULE_AI_ID, self::OPTION_TUNING, '');
		foreach (self::AIASSISTANT_SCENARIO_OPTIONS as $optionName)
		{
			$key .= '|' . Option::get(self::MODULE_AIASSISTANT_ID, $optionName, 'N');
		}

		return $key;
	}

	private static function detectEnabledAiScenario(): bool
	{
		$manager = new Manager();
		foreach ($manager->getList() as $group)
		{
			$items = $group->getItems();

			// A child switch keeps its stored value while its parent is off: the settings page
			// only hides the row, so such a child is not a scenario the admin enabled.
			$hiddenChildren = [];
			foreach ($group->toArray()['relations'] ?? [] as $relation)
			{
				if ($items->get($relation['parent'])?->getValue() === false)
				{
					array_push($hiddenChildren, ...$relation['children']);
				}
			}

			foreach ($items as $item)
			{
				if (Defaults::isItemInternal($item) || in_array($item->getCode(), $hiddenChildren, true))
				{
					continue;
				}

				if ($item->isBoolean() && $item->getValue() === true)
				{
					return true;
				}
			}
		}

		if (Loader::includeModule(self::MODULE_AIASSISTANT_ID))
		{
			foreach (self::AIASSISTANT_SCENARIO_OPTIONS as $optionName)
			{
				if (Option::get(self::MODULE_AIASSISTANT_ID, $optionName, 'N') === 'Y')
				{
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Switches off every AI scenario in the portal settings.
	 */
	public static function disableAllAiScenarios(): void
	{
		$manager = new Manager();
		$storage = Manager::getTuningStorage();

		foreach ($manager->getList() as $group)
		{
			foreach ($group->getItems() as $item)
			{
				if (Defaults::isItemInternal($item) || !$item->isBoolean())
				{
					continue;
				}

				$storage[$item->getCode()] = false;
			}
		}

		Option::set(self::MODULE_AI_ID, self::OPTION_TUNING, Json::encode($storage));

		if (Loader::includeModule(self::MODULE_AIASSISTANT_ID))
		{
			foreach (self::AIASSISTANT_SCENARIO_OPTIONS as $optionName)
			{
				Option::set(self::MODULE_AIASSISTANT_ID, $optionName, 'N');
			}
		}
	}

	public static function hasVibePlusMonetizationModel(): bool
	{
		$runtimeStateProvider = self::getVibePlusRuntimeStateProvider();

		return $runtimeStateProvider !== null
			&& $runtimeStateProvider->getMonetizationModel() === MonetizationModel::VIBE_PLUS;
	}

	public static function isVibePlusLaunchDateReached(): bool
	{
		$runtimeStateProvider = self::getVibePlusRuntimeStateProvider();

		return $runtimeStateProvider !== null && $runtimeStateProvider->isLaunchDateReached();
	}

	public static function isCurrentEditionActive(): bool
	{
		$runtimeStateProvider = self::getVibePlusRuntimeStateProvider();

		return $runtimeStateProvider !== null && $runtimeStateProvider->isCurrentEditionActive();
	}

	public static function hasActiveVibePlusEdition(): bool
	{
		$runtimeStateProvider = self::getVibePlusRuntimeStateProvider();

		return $runtimeStateProvider !== null
			&& method_exists($runtimeStateProvider, 'hasActiveVibePlusEdition')
			&& $runtimeStateProvider->hasActiveVibePlusEdition();
	}

	public static function isOnVibePlus(): bool
	{
		$licenseType = Bitrix24::getLicenseType();

		return $licenseType !== null && self::isVibePlusLicenseType($licenseType);
	}

	/**
	 * Vibe+ edition is either a paid `*_vibe` one or the classic demo, which plays
	 * the role of the trial edition in the Vibe+ line-up.
	 *
	 * Line-up membership only, meant for choosing the wording: this is NOT the "unlimited AI"
	 * feature. The two sets differ both ways - `basic_vibe` belongs to the line-up without
	 * unlimited AI, `nfr` has unlimited AI outside the line-up - so quota decisions must read
	 * the `ai_unlimited_by_version` feature instead.
	 */
	public static function isVibePlusLicenseType(string $licenseType): bool
	{
		return str_ends_with($licenseType, self::VIBE_PLUS_LICENSE_TYPE_SUFFIX)
			|| $licenseType === self::DEMO_LICENSE_TYPE;
	}

	public static function isDemoAvailable(): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return false;
		}

		return License::getCurrent()->getDemo()->isAvailable();
	}

	private static function getVibePlusRuntimeStateProvider(): ?RuntimeStateProvider
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return null;
		}

		// bitrix24 is updated independently, so an older one may not carry the Vibe+ line-up yet
		if (!class_exists(RuntimeStateProvider::class))
		{
			if (!self::$missingRuntimeStateProviderWarningLogged)
			{
				$logger = (new LoggerFactory())->createById(self::VIBE_PLUS_LOGGER_ID) ?? new NullLogger();
				$logger->warning('Vibe+ runtime state provider is unavailable; limit messages keep the current behaviour.');
				self::$missingRuntimeStateProviderWarningLogged = true;
			}

			return null;
		}

		return new RuntimeStateProvider();
	}
}
