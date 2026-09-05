<?php

namespace Bitrix\Main\Config\Feature;

/**
 * Base class for runtime rules.
 */
abstract class AbstractRule
{
	/**
	 * Returns the unique code of the rule.
	 * The code is the fully qualified class name of the rule.
	 *
	 * @return string
	 */
	final public function getCode(): string
	{
		return static::class;
	}

	/**
	 * Creates a rule from a config.
	 *
	 * @param array $config
	 * @return static
	 */
	abstract public static function createFromConfig(array $config = []): static;

	/**
	 * Checks if the rule is met.
	 *
	 * @param Context $context
	 * @return bool
	 */
	abstract public function check(Context $context): bool;

	public function __invoke(Context $context): bool
	{
		return $this->check($context);
	}
}
