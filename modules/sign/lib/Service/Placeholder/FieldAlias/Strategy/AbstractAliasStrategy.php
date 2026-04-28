<?php

namespace Bitrix\Sign\Service\Placeholder\FieldAlias\Strategy;

use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Placeholder\FieldAlias\AliasContext;
use Bitrix\Sign\Service\Placeholder\FieldAlias\AliasRoleResolver;
use Bitrix\Sign\Service\Placeholder\FieldAlias\FieldNameTransformer;
use Bitrix\Sign\Helper\Field\NameHelper;

abstract class AbstractAliasStrategy implements AliasStrategyInterface
{
	private readonly AliasRoleResolver $aliasRoleResolver;

	public function __construct(?AliasRoleResolver $aliasRoleResolver = null)
	{
		$this->aliasRoleResolver = $aliasRoleResolver ?? Container::instance()->getAliasRoleResolver();
	}

	/**
	 * Length of timestamp used in dynamic field codes
	 * Example: UF_CRM_COMPANY_1738924800000
	 */
	protected const DYNAMIC_FIELD_TIMESTAMP_LENGTH = 13;
	
	/**
	 * Common type shortcuts used across all strategies
	 */
	protected const TYPE_SHORTCUTS = [
		'string' => 'Str',
		'date' => 'Date',
		'datetime' => 'DateTime',
		'integer' => 'Int',
		'double' => 'Num',
		'boolean' => 'Bool',
		'address' => 'Addr',
	];
	
	public function getAliasPrefixes(): array
	{
		return [];
	}

	protected function getRoleName(AliasContext $context, ?int $party = null): string
	{
		return $this->aliasRoleResolver->getShortRoleNameByContext($context, $party);
	}
	
	protected function getPartyFromRoleName(AliasContext $context, string $roleName): ?int
	{
		return $this->aliasRoleResolver->getPartyByRoleNameAndContext($context, $roleName);
	}
	
	protected function parseFieldName(string $fieldName): ?array
	{
		$parsed = NameHelper::parse($fieldName);
		
		if (!NameHelper::isValidParsedField($parsed))
		{
			return null;
		}
		
		return $parsed;
	}

	/**
	 * Validate required parsed field keys and their scalar types.
	 * String values must be non-empty; int values must be real ints.
	 * @param array<string, mixed>|null $parsed
	 * @param string[] $requiredStringKeys
	 * @param string[] $requiredIntKeys
	 */
	protected function hasRequiredParsedValues(
		?array $parsed,
		array $requiredStringKeys = [],
		array $requiredIntKeys = [],
	): bool
	{
		if ($parsed === null)
		{
			return false;
		}

		foreach ($requiredStringKeys as $key)
		{
			if (!isset($parsed[$key]) || !is_string($parsed[$key]) || $parsed[$key] === '')
			{
				return false;
			}
		}

		foreach ($requiredIntKeys as $key)
		{
			if (!isset($parsed[$key]) || !is_int($parsed[$key]))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Parse alias with expected prefix and resolve party by role.
	 * @return array{fieldPart: string, party: int}|null
	 */
	protected function parseAliasFieldPartWithParty(
		string $alias,
		AliasContext $context,
		string $expectedPrefix,
	): ?array
	{
		$parts = explode('.', $alias);

		if (count($parts) !== FieldNameTransformer::FIELD_PARTS_COUNT)
		{
			return null;
		}

		[$prefix, $roleName, $fieldPart] = $parts;

		if ($prefix !== $expectedPrefix)
		{
			return null;
		}

		$party = $this->getPartyFromRoleName($context, $roleName);
		if ($party === null)
		{
			return null;
		}

		return [
			'fieldPart' => $fieldPart,
			'party' => $party,
		];
	}
		
	protected function createFieldName(
		string $blockCode,
		string $fieldType,
		int $party,
		?string $fieldCode = null,
		?string $subfieldCode = null,
	): string
	{
		return NameHelper::create($blockCode, $fieldType, $party, $fieldCode, $subfieldCode);
	}
	
	/**
	 * Extract timestamp from field code
	 * @param string $fieldCode Field code to extract timestamp from
	 * @return string|null Extracted timestamp or null if not found
	 */
	protected function extractTimestampFromFieldCode(string $fieldCode): ?string
	{
		$pattern = '/(\d{' . self::DYNAMIC_FIELD_TIMESTAMP_LENGTH . '})$/';
		
		if (preg_match($pattern, $fieldCode, $matches))
		{
			return $matches[1];
		}
		
		return null;
	}

	protected function buildDynamicAliasFromParsedField(
		array $parsed,
		AliasContext $context,
		string $aliasPrefix,
	): ?string
	{
		if (!$this->hasRequiredParsedValues($parsed, ['fieldCode', 'fieldType'], ['party']))
		{
			return null;
		}

		$fieldCode = $parsed['fieldCode'];
		$fieldType = $parsed['fieldType'];
		$party = $parsed['party'];

		$timestamp = $this->extractTimestampFromFieldCode($fieldCode);
		if ($timestamp === null)
		{
			return null;
		}

		$roleName = $this->getRoleName($context, $party);
		$typeShortcut = self::TYPE_SHORTCUTS[$fieldType] ?? ucfirst($fieldType);

		return $aliasPrefix . ".{$roleName}.{$typeShortcut}{$timestamp}";
	}
}
