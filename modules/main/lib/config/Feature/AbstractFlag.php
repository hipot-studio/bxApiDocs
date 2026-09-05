<?php

namespace Bitrix\Main\Config\Feature;

/**
 * Base class for feature flags.
 * @see \Bitrix\Main\Config\Feature
 */
abstract class AbstractFlag
{
	/**
	 * Returns the unique code of the feature flag.
	 * The code is the fully qualified class name of the flag.
	 *
	 * @return string
	 */
	final public function getCode(): string
	{
		return static::class;
	}

	/**
	 * Returns a list of requirements for the feature flag.
	 *
	 * @return callable[]
	 */
	public function getRequirements(): array
	{
		return [];
	}

	/**
	 * Returns true if the feature is enabled by default.
	 *
	 * @return bool
	 */
	public function enabledByDefault(): bool
	{
		return false;
	}

	/**
	 * Do not call this method directly. It must be called only from FeatureManager,
	 * when flag value was actually changed in database.
	 *
	 * @param bool $newValue
	 * @return void
	 */
	final public function onFlagValueChange(bool $newValue): void
	{
		if ($newValue === true)
		{
			$this->onEnable();
		}
		else
		{
			$this->onDisable();
		}
	}

	/**
	 * Hook is called after the feature is enabled.
	 * Note: called only if flag value was actually changed in database. Will not call, if feature was already enabled.
	 *
	 * @return void
	 */
	protected function onEnable(): void
	{
	}

	/**
	 * Hook is called after the feature is disabled.
	 * Note: called only if flag value was actually changed in database. Will not call, if feature was already disabled.
	 *
	 * @return void
	 */
	protected function onDisable(): void
	{
	}
}
