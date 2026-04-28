<?php

namespace Bitrix\Sign\Service\Placeholder\FieldAlias\Strategy;

use Bitrix\Sign\Service\Placeholder\FieldAlias\AliasContext;

interface AliasStrategyInterface
{
	public function getAliasPrefixes(): array;
	public function supportsFieldName(string $fieldName): bool;
	public function supportsAlias(string $alias): bool;
	public function fieldNameToAlias(string $fieldName, AliasContext $context): ?string;
	public function aliasToFieldName(string $alias, AliasContext $context): ?string;
}
