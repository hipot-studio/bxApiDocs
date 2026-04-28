<?php

namespace Bitrix\Sign\Service\Placeholder\FieldAlias;

use Bitrix\Sign\Service\Placeholder\FieldAlias\Strategy\AliasStrategyInterface;
use Bitrix\Sign\Service\Placeholder\FieldAlias\Strategy\PreloadableStrategyInterface;

/**
 * Registry for fast strategy resolution by alias prefix
 */
class StrategyRegistry
{
	/** @var array<string, AliasStrategyInterface> */
	private array $aliasToStrategyMap = [];
	
	/** @var AliasStrategyInterface[] */
	private array $allStrategies;

	/**
	 * @param AliasStrategyInterface[] $strategies
	 */
	public function __construct(array $strategies)
	{
		$this->allStrategies = $strategies;
		$this->buildAliasMap();
	}

	public function getStrategyForAlias(string $alias): ?AliasStrategyInterface
	{
		$prefix = $this->extractPrefix($alias);
		
		return $this->aliasToStrategyMap[$prefix] ?? null;
	}

	public function getStrategyForFieldName(string $fieldName): ?AliasStrategyInterface
	{
		foreach ($this->allStrategies as $strategy)
		{
			if ($strategy->supportsFieldName($fieldName))
			{
				return $strategy;
			}
		}

		return null;
	}

	/**
	 * @param string[] $fieldNames
	 */
	public function preloadForFieldNames(array $fieldNames): void
	{
		foreach ($this->allStrategies as $strategy)
		{
			if ($strategy instanceof PreloadableStrategyInterface)
			{
				$strategy->preloadForFieldNames($fieldNames);
			}
		}
	}

	/**
	 * @param string[] $aliases
	 */
	public function preloadForAliases(array $aliases, AliasContext $context): void
	{
		foreach ($this->allStrategies as $strategy)
		{
			if ($strategy instanceof PreloadableStrategyInterface)
			{
				$strategy->preloadForAliases($aliases, $context);
			}
		}
	}

	private function buildAliasMap(): void
	{
		foreach ($this->allStrategies as $strategy)
		{
			$prefixes = $strategy->getAliasPrefixes();
			
			foreach ($prefixes as $prefix)
			{
				$this->aliasToStrategyMap[$prefix] = $strategy;
			}
		}
	}

	private function extractPrefix(string $alias): string
	{
		$dotPosition = strpos($alias, '.');
		
		if ($dotPosition === false)
		{
			return $alias;
		}
		
		return substr($alias, 0, $dotPosition);
	}
}
