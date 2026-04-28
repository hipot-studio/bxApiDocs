<?php

namespace Bitrix\Sign\Service\Placeholder\FieldAlias;

use Bitrix\Sign\Service\Placeholder\FieldAlias\Strategy\HcmLinkFieldStrategy;
use Bitrix\Sign\Service\Placeholder\FieldAlias\Strategy\UserLegalFieldStrategy;
use Bitrix\Sign\Service\Placeholder\FieldAlias\Strategy\DynamicFieldStrategy;
use Bitrix\Sign\Service\Placeholder\FieldAlias\Strategy\DocumentFieldStrategy;
use Bitrix\Sign\Service\Placeholder\FieldAlias\Strategy\CompanyFieldStrategy;

class FieldAliasService
{
	private const MAX_ALIAS_LENGTH = 285;
	
	private StrategyRegistry $registry;

	public function __construct(array $strategies = [])
	{
		if (empty($strategies))
		{
			$strategies = $this->createDefaultStrategies();
		}
		
		$this->registry = new StrategyRegistry($strategies);
	}

	private function createDefaultStrategies(): array
	{
		return [
			new HcmLinkFieldStrategy(),
			new DynamicFieldStrategy(),
			new UserLegalFieldStrategy(),
			new DocumentFieldStrategy(),
			new CompanyFieldStrategy(),
		];
	}

	public function toAlias(string $fieldName, AliasContext $context): ?string
	{
		$strategy = $this->registry->getStrategyForFieldName($fieldName);
		
		if ($strategy === null)
		{
			return null;
		}

		$alias = $strategy->fieldNameToAlias($fieldName, $context);
		
		if ($alias === null)
		{
			return null;
		}
		
		if (mb_strlen($alias) > self::MAX_ALIAS_LENGTH)
		{
			return null;
		}
		
		return $alias;
	}

	public function toFieldName(string $alias, AliasContext $context): ?string
	{
		return $this->registry->getStrategyForAlias($alias)?->aliasToFieldName($alias, $context);
	}

	public function toAliases(array $fieldNames, AliasContext $context): array
	{
		$this->registry->preloadForFieldNames($fieldNames);

		$result = [];

		foreach ($fieldNames as $fieldName)
		{
			$alias = $this->toAlias($fieldName, $context);

			if ($alias !== null)
			{
				$result[$fieldName] = $alias;
			}
		}

		return $result;
	}

	public function toFieldNames(array $aliases, AliasContext $context): array
	{
		$this->registry->preloadForAliases($aliases, $context);

		$result = [];

		foreach ($aliases as $alias)
		{
			$fieldName = $this->toFieldName($alias, $context);
			
			if ($fieldName !== null)
			{
				$result[$alias] = $fieldName;
			}
		}
		
		return $result;
	}
}
