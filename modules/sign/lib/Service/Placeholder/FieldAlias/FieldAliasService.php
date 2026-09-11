<?php

namespace Bitrix\Sign\Service\Placeholder\FieldAlias;

use Bitrix\Sign\Helper\Field\NameHelper;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Placeholder\FieldAlias\Strategy\HcmLinkFieldStrategy;
use Bitrix\Sign\Service\Placeholder\FieldAlias\Strategy\UserLegalFieldStrategy;
use Bitrix\Sign\Service\Placeholder\FieldAlias\Strategy\DynamicFieldStrategy;
use Bitrix\Sign\Service\Placeholder\FieldAlias\Strategy\DocumentFieldStrategy;
use Bitrix\Sign\Service\Placeholder\FieldAlias\Strategy\CompanyFieldStrategy;
use Psr\Log\LoggerInterface;

class FieldAliasService
{
	private const MAX_ALIAS_LENGTH = 285;

	private StrategyRegistry $registry;
	private readonly LoggerInterface $logger;

	public function __construct(array $strategies = [], ?LoggerInterface $logger = null)
	{
		if (empty($strategies))
		{
			$strategies = $this->createDefaultStrategies();
		}

		$this->registry = new StrategyRegistry($strategies);
		$this->logger = $logger ?? Container::instance()->getLogger('Service');
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

	/**
	 * Returns an alias only when it survives a full field name -> alias -> field name roundtrip.
	 * fieldType and subfieldCode are excluded from comparison on purpose: the type is restored
	 * by a heuristic and may legitimately differ.
	 */
	public function toVerifiedAlias(string $fieldName, AliasContext $context): ?string
	{
		$alias = $this->toAlias($fieldName, $context);
		if ($alias === null)
		{
			$this->logger->debug('Placeholder field hidden: alias is not formed', [
				'fieldName' => $fieldName,
			]);

			return null;
		}

		$restored = $this->toFieldName($alias, $context);
		if ($restored === null)
		{
			$this->logger->debug('Placeholder field hidden: reverse resolve failed', [
				'fieldName' => $fieldName,
				'alias' => $alias,
			]);

			return null;
		}

		$source = NameHelper::parse($fieldName);
		$back = NameHelper::parse($restored);

		if (
			$source['fieldCode'] !== $back['fieldCode']
			|| $source['blockCode'] !== $back['blockCode']
			|| $source['party'] !== $back['party']
		)
		{
			$this->logger->debug('Placeholder field hidden: roundtrip mismatch', [
				'fieldName' => $fieldName,
				'alias' => $alias,
				'restored' => $restored,
			]);

			return null;
		}

		return $alias;
	}

	public function preloadForFieldNames(array $fieldNames, ?AliasContext $context = null): void
	{
		$this->registry->preloadForFieldNames($fieldNames, $context);
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
