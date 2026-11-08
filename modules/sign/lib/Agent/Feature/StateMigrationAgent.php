<?php

namespace Bitrix\Sign\Agent\Feature;

use Bitrix\Main;
use Bitrix\Main\Config\Feature\FeatureFlagRuleTable;
use Bitrix\Main\Config\Feature\FeatureFlagTable;
use Bitrix\Main\Config\Option;
use Bitrix\Sign\Config\Feature;
use Bitrix\Sign\Config\Feature\B2eBizprocChainFlag;
use Bitrix\Sign\Config\Feature\KedoRunProcessFlag;
use Bitrix\Sign\Config\FeatureStateLifecycle;

final class StateMigrationAgent
{
	private const MODULE_ID = 'sign';
	private const B2E_BIZPROC_PUBLICITY_SETTING = 'service.b2e.bizproc-chain.publicity';
	private const DISABLED_OPTION_VALUES = ['', '0', 'N'];

	/** @var array<string, class-string<Main\Config\Feature\AbstractFlag>> */
	private const FLAGS = [
		Feature::SIGN_B2E_BIZPROC_CHAIN_ENABLED_OPTION => B2eBizprocChainFlag::class,
		Feature::SIGN_KEDO_RUN_PROCESS_ENABLED_OPTION => KedoRunProcessFlag::class,
	];

	public static function run(): string
	{
		try
		{
			if (!self::isInfrastructureAvailable())
			{
				return self::getRetryCall();
			}
			if (!FeatureStateLifecycle::restore())
			{
				self::removeMigrationMarker();

				return self::getRetryCall();
			}
			if (self::isMigrationCompleted())
			{
				return '';
			}
			if (self::hasSiteSpecificLegacyState())
			{
				return '';
			}

			foreach (self::FLAGS as $optionName => $flagClass)
			{
				if (self::hasStoredState($flagClass))
				{
					continue;
				}

				$expectedState = self::resolveExpectedState($optionName);
				if ($expectedState !== null && !self::writeAndVerifyState($flagClass, $expectedState))
				{
					self::removeMigrationMarker();

					return self::getRetryCall();
				}
			}

			Option::set(
				self::MODULE_ID,
				Feature::SIGN_KEDO_BIZPROC_FLAGS_MIGRATED_TO_MAIN_OPTION,
				'Y',
			);
			if (!self::isMigrationCompleted())
			{
				self::removeMigrationMarker();

				return self::getRetryCall();
			}

			return '';
		}
		catch (\Throwable)
		{
			self::removeMigrationMarker();

			return self::getRetryCall();
		}
	}

	private static function isInfrastructureAvailable(): bool
	{
		if (
			!class_exists(Main\Config\Feature::class)
			|| !class_exists(Main\Config\Feature\AbstractFlag::class)
			|| !class_exists(FeatureFlagTable::class)
			|| !class_exists(FeatureFlagRuleTable::class)
			|| !class_exists(B2eBizprocChainFlag::class)
			|| !class_exists(KedoRunProcessFlag::class)
			|| !is_subclass_of(B2eBizprocChainFlag::class, Main\Config\Feature\AbstractFlag::class)
			|| !is_subclass_of(KedoRunProcessFlag::class, Main\Config\Feature\AbstractFlag::class)
		)
		{
			return false;
		}

		$connection = Main\Application::getConnection();

		return
			$connection->isTableExists(FeatureFlagTable::getTableName())
			&& $connection->isTableExists(FeatureFlagRuleTable::getTableName())
		;
	}

	private static function resolveExpectedState(string $optionName): ?bool
	{
		$legacyValue = Option::getRealValue(self::MODULE_ID, $optionName, '');
		if ($legacyValue !== null)
		{
			return !in_array((string)$legacyValue, self::DISABLED_OPTION_VALUES, true);
		}

		if ($optionName !== Feature::SIGN_B2E_BIZPROC_CHAIN_ENABLED_OPTION)
		{
			return null;
		}

		return self::isBizprocChainEnabledByLegacyDefault() ? true : null;
	}

	private static function hasSiteSpecificLegacyState(): bool
	{
		$sites = Main\SiteTable::query()
			->setSelect(['LID'])
			->exec()
		;
		while ($site = $sites->fetch())
		{
			foreach (array_keys(self::FLAGS) as $optionName)
			{
				if (Option::getRealValue(self::MODULE_ID, $optionName, $site['LID']) !== null)
				{
					return true;
				}
			}
		}

		return false;
	}

	private static function isBizprocChainEnabledByLegacyDefault(): bool
	{
		$rootConfiguration = Main\Config\Configuration::getValue(self::MODULE_ID);
		$publicitySettings = is_array($rootConfiguration)
			? ($rootConfiguration[self::B2E_BIZPROC_PUBLICITY_SETTING] ?? null)
			: null
		;
		if ($publicitySettings === null)
		{
			$publicitySettings = Main\Config\Configuration::getInstance(self::MODULE_ID)
				->get(self::B2E_BIZPROC_PUBLICITY_SETTING)
			;
		}
		if (!is_array($publicitySettings))
		{
			return false;
		}

		$region = Main\Application::getInstance()->getLicense()->getRegion();

		return (bool)($publicitySettings[$region] ?? false);
	}

	private static function hasStoredState(string $flagClass): bool
	{
		return self::readStoredState($flagClass) !== null;
	}

	private static function writeAndVerifyState(string $flagClass, bool $expectedState): bool
	{
		if (
			!class_exists($flagClass)
			|| !is_subclass_of($flagClass, Main\Config\Feature\AbstractFlag::class)
		)
		{
			return false;
		}

		if ($expectedState)
		{
			Main\Config\Feature::enable($flagClass);
		}
		else
		{
			Main\Config\Feature::disable($flagClass);
		}

		return self::readStoredState($flagClass) === $expectedState;
	}

	private static function readStoredState(string $flagClass): ?bool
	{
		$row = FeatureFlagTable::query()
			->setSelect(['CODE', 'ENABLED'])
			->where('CODE', $flagClass)
			->exec()
			->fetch()
		;
		if ($row === false)
		{
			return null;
		}

		return
			$row['ENABLED'] === true
			|| $row['ENABLED'] === 1
			|| $row['ENABLED'] === '1'
			|| $row['ENABLED'] === 'Y'
		;
	}

	private static function isMigrationCompleted(): bool
	{
		return Option::getRealValue(
			self::MODULE_ID,
			Feature::SIGN_KEDO_BIZPROC_FLAGS_MIGRATED_TO_MAIN_OPTION,
			'',
		) === 'Y';
	}

	private static function removeMigrationMarker(): void
	{
		try
		{
			Option::delete(self::MODULE_ID, [
				'name' => Feature::SIGN_KEDO_BIZPROC_FLAGS_MIGRATED_TO_MAIN_OPTION,
				'site_id' => '',
			]);
		}
		catch (\Throwable)
		{
		}
	}

	private static function getRetryCall(): string
	{
		return self::class . '::run();';
	}
}
