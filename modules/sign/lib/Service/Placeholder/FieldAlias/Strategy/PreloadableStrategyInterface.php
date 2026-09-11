<?php

namespace Bitrix\Sign\Service\Placeholder\FieldAlias\Strategy;

use Bitrix\Sign\Service\Placeholder\FieldAlias\AliasContext;

interface PreloadableStrategyInterface
{
	/**
	 * @param string[] $fieldNames
	 */
	public function preloadForFieldNames(array $fieldNames, ?AliasContext $context = null): void;

	/**
	 * @param string[] $aliases
	 */
	public function preloadForAliases(array $aliases, AliasContext $context): void;
}
