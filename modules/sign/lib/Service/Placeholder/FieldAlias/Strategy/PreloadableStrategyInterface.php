<?php

namespace Bitrix\Sign\Service\Placeholder\FieldAlias\Strategy;

use Bitrix\Sign\Service\Placeholder\FieldAlias\AliasContext;

interface PreloadableStrategyInterface
{
	/**
	 * @param string[] $fieldNames
	 */
	public function preloadForFieldNames(array $fieldNames): void;

	/**
	 * @param string[] $aliases
	 */
	public function preloadForAliases(array $aliases, AliasContext $context): void;
}
