<?php

namespace Bitrix\Main\Config;

use Bitrix\Main\Config\Feature\AbstractFlag;
use Bitrix\Main\Config\Feature\AbstractRule;
use Bitrix\Main\Config\Feature\Context;
use Bitrix\Main\Config\Feature\FeatureManager;
use Bitrix\Main\DI\ServiceLocator;

final class Feature
{
	/**
	 * Checks if a feature is enabled.
	 *
	 * @param class-string<AbstractFlag> $flag The feature flag class to check.
	 * @param Context|null $context The context for the check (e.g., a specific user).
	 * @return bool
	 */
	public static function isEnabled(string $flag, ?Context $context = null): bool
	{
		return self::getManager()->isEnabled($flag, $context);
	}

	/**
	 * Checks if a feature is disabled.
	 *
	 * @param class-string<AbstractFlag> $flag The feature flag class to check.
	 * @param Context|null $context The context for the check (e.g., a specific user).
	 * @return bool
	 */
	public static function isDisabled(string $flag, ?Context $context = null): bool
	{
		return self::getManager()->isDisabled($flag, $context);
	}

	/**
	 * Enables one or more feature flags.
	 *
	 * @param class-string<AbstractFlag> ...$flags
	 * @return void
	 */
	public static function enable(string ...$flags): void
	{
		self::getManager()->enable(...$flags);
	}

	/**
	 * Disables one or more feature flags.
	 *
	 * @param class-string<AbstractFlag> ...$flags
	 * @return void
	 */
	public static function disable(string ...$flags): void
	{
		self::getManager()->disable(...$flags);
	}

	/**
	 * Adds a rule to conditionally allow a feature.
	 *
	 * @param class-string<AbstractFlag> $flag The feature flag class.
	 * @param class-string<AbstractRule> $rule The rule class.
	 * @param array $config The configuration for the rule.
	 * @return void
	 */
	public static function allow(string $flag, string $rule, array $config = []): void
	{
		self::getManager()->allow($flag, $rule, $config);
	}

	/**
	 * Adds a rule to conditionally deny a feature.
	 *
	 * @param class-string<AbstractFlag> $flag The feature flag class.
	 * @param class-string<AbstractRule> $rule The rule class.
	 * @param array $config The configuration for the rule.
	 * @return void
	 */
	public static function deny(string $flag, string $rule, array $config = []): void
	{
		self::getManager()->deny($flag, $rule, $config);
	}

	/**
	 * Clears runtime rules for a feature flag.
	 *
	 * @param class-string<AbstractFlag> $flag The feature flag class.
	 * @param class-string<AbstractRule> ...$rules If provided, only these rules will be cleared.
	 * @return void
	 */
	public static function clearRules(string $flag, string ...$rules): void
	{
		self::getManager()->clearRules($flag, ...$rules);
	}

	/**
	 * Resets previously toggled feature flags to default value.
	 * Note that this method don't remove rules, use specific clearRules() method for that purpose.
	 *
	 * @param class-string<AbstractFlag> ...$flags The feature flag classes.
	 * @return void
	 */
	public static function resetToDefault(string ...$flags): void
	{
		self::getManager()->resetToDefault(...$flags);
	}

	/**
	 * Clears whole cache, both in-memory and ORM
	 *
	 * @return void
	 */
	public static function clearCache(): void
	{
		self::getManager()->clearCache();
	}

	private static function getManager(): FeatureManager
	{
		return ServiceLocator::getInstance()->get(FeatureManager::class);
	}
}
