<?php

namespace Bitrix\Sign\Service\Placeholder\FieldAlias\Strategy;

use Bitrix\Sign\Service\Placeholder\FieldAlias\AliasContext;
use Bitrix\Sign\Service\Placeholder\FieldAlias\AliasRoleResolver;
use Bitrix\Sign\Service\Placeholder\FieldAlias\FieldNameTransformer;
use Bitrix\Sign\Type\BlockCode;
use Bitrix\Sign\Type\FieldType;

class CompanyFieldStrategy extends AbstractAliasStrategy
{
	private const ALIAS_PREFIX = 'Com';
	private const BLOCK_CODE = BlockCode::B2E_MY_REFERENCE;
	private const UF_PREFIX = 'COMPANY_UF_CRM_COMPANY_';
	private const LEGACY_UF_PREFIX = 'COMPANY_UF_CRM_';
	private const LEGACY_DYNAMIC_FIELD_TIMESTAMP_LENGTH = 10;
	private const DYNAMIC_ALIAS_TYPE_PATTERN = '[A-Za-z][A-Za-z_]*';
	
	private FieldNameTransformer $transformer;

	public function __construct(
		?FieldNameTransformer $transformer = null,
		?AliasRoleResolver $aliasRoleResolver = null,
	)
	{
		parent::__construct($aliasRoleResolver);
		$this->transformer = $transformer ?? new FieldNameTransformer();
	}

	public function getAliasPrefixes(): array
	{
		return [self::ALIAS_PREFIX];
	}

	public function supportsFieldName(string $fieldName): bool
	{
		$parsed = $this->parseFieldName($fieldName);
		
		if (!$this->hasRequiredParsedValues($parsed, ['blockCode', 'fieldCode']))
		{
			return false;
		}

		if ($parsed['blockCode'] !== self::BLOCK_CODE)
		{
			return false;
		}

		$fieldCode = $parsed['fieldCode'];

		if ($this->isCurrentUfField($fieldCode) || $this->isLegacyUfField($fieldCode))
		{
			return true;
		}

		if ($this->isMalformedDynamicUfField($fieldCode))
		{
			return false;
		}

		return $this->transformer->isCompanyField($fieldCode);
	}

	public function supportsAlias(string $alias): bool
	{
		return str_starts_with($alias, self::ALIAS_PREFIX . '.');
	}

	public function fieldNameToAlias(string $fieldName, AliasContext $context): ?string
	{
		$parsed = $this->parseFieldName($fieldName);
		
		if (!$this->hasRequiredParsedValues($parsed, ['fieldCode'], ['party']))
		{
			return null;
		}

		$fieldCode = $parsed['fieldCode'];
		$party = $parsed['party'];
		
		if ($this->isCurrentUfField($fieldCode))
		{
			return $this->buildDynamicAliasFromParsedField($parsed, $context, self::ALIAS_PREFIX);
		}

		if ($this->isLegacyUfField($fieldCode))
		{
			return $this->buildLegacyDynamicAlias($parsed, $context);
		}

		if ($this->isMalformedDynamicUfField($fieldCode))
		{
			return null;
		}

		$shortFieldName = $this->transformer->toShortName($fieldCode);

		if ($shortFieldName !== $fieldCode)
		{
			$roleName = $this->getRoleName($context, $party);

			return self::ALIAS_PREFIX . ".{$roleName}.{$shortFieldName}";
		}
		
		return null;
	}

	public function aliasToFieldName(string $alias, AliasContext $context): ?string
	{
		$aliasData = $this->parseAliasFieldPartWithParty($alias, $context, self::ALIAS_PREFIX);
		if ($aliasData === null)
		{
			return null;
		}

		$shortFieldName = $aliasData['fieldPart'];
		$party = $aliasData['party'];
		
		$dynamicAliasData = $this->parseDynamicAliasField($shortFieldName);
		if ($dynamicAliasData !== null)
		{
			return $this->createFieldName(
				blockCode: self::BLOCK_CODE,
				fieldType: $dynamicAliasData['fieldType'],
				party: $party,
				fieldCode: $dynamicAliasData['fieldCode'],
			);
		}
		
		$fieldCode = $this->transformer->toFullFieldCode($shortFieldName);
		
		if ($fieldCode !== null)
		{
			$fieldType = $this->getFieldType($fieldCode);
			
			return $this->createFieldName(
				blockCode: self::BLOCK_CODE,
				fieldType: $fieldType,
				party: $party,
				fieldCode: $fieldCode,
			);
		}
		
		return null;
	}

	private function isCurrentUfField(string $fieldCode): bool
	{
		return $this->matchesUfPattern(
			$fieldCode,
			self::UF_PREFIX,
			self::DYNAMIC_FIELD_TIMESTAMP_LENGTH);
	}

	private function isLegacyUfField(string $fieldCode): bool
	{
		return $this->matchesUfPattern(
			$fieldCode,
			self::LEGACY_UF_PREFIX,
			self::LEGACY_DYNAMIC_FIELD_TIMESTAMP_LENGTH,
		);
	}

	private function matchesUfPattern(string $fieldCode, string $prefix, int $timestampLength): bool
	{
		$pattern = '/^' . preg_quote($prefix, '/') . '\d{' . $timestampLength . '}$/';

		return preg_match($pattern, $fieldCode) === 1;
	}

	private function buildLegacyDynamicAlias(array $parsed, AliasContext $context): ?string
	{
		if (!$this->hasRequiredParsedValues($parsed, ['fieldCode', 'fieldType'], ['party']))
		{
			return null;
		}

		$timestamp = substr($parsed['fieldCode'], strlen(self::LEGACY_UF_PREFIX));
		$roleName = $this->getRoleName($context, $parsed['party']);
		$typeShortcut = self::TYPE_SHORTCUTS[$parsed['fieldType']] ?? ucfirst($parsed['fieldType']);

		return self::ALIAS_PREFIX . ".{$roleName}.{$typeShortcut}{$timestamp}";
	}

	/**
	 * @return array{fieldType: string, fieldCode: string}|null
	 */
	private function parseDynamicAliasField(string $fieldPart): ?array
	{
		$typePattern = self::DYNAMIC_ALIAS_TYPE_PATTERN;
		$currentTs = '\d{' . self::DYNAMIC_FIELD_TIMESTAMP_LENGTH . '}';
		$legacyTs = '\d{' . self::LEGACY_DYNAMIC_FIELD_TIMESTAMP_LENGTH . '}';
		$pattern = '/^(' . $typePattern . ')(' . $currentTs . '|' . $legacyTs . ')$/';

		if (!preg_match($pattern, $fieldPart, $matches))
		{
			return null;
		}

		[, $typeShortcut, $timestamp] = $matches;

		return [
			'fieldType' => $this->resolveDynamicFieldType($typeShortcut),
			'fieldCode' => $this->resolveUfPrefixByTimestamp($timestamp) . $timestamp,
		];
	}

	private function resolveDynamicFieldType(string $typeShortcut): string
	{
		$fieldType = array_search($typeShortcut, self::TYPE_SHORTCUTS, true);

		return $fieldType !== false ? $fieldType : strtolower($typeShortcut);
	}

	private function resolveUfPrefixByTimestamp(string $timestamp): string
	{
		return strlen($timestamp) === self::DYNAMIC_FIELD_TIMESTAMP_LENGTH
			? self::UF_PREFIX
			: self::LEGACY_UF_PREFIX;
	}

	private function isMalformedDynamicUfField(string $fieldCode): bool
	{
		if (str_starts_with($fieldCode, self::UF_PREFIX))
		{
			return $this->hasInvalidTimestampSuffix(
				$fieldCode,
				self::UF_PREFIX,
				self::DYNAMIC_FIELD_TIMESTAMP_LENGTH,
			);
		}

		if (str_starts_with($fieldCode, self::LEGACY_UF_PREFIX))
		{
			return $this->hasInvalidTimestampSuffix(
				$fieldCode,
				self::LEGACY_UF_PREFIX,
				self::LEGACY_DYNAMIC_FIELD_TIMESTAMP_LENGTH,
			);
		}

		return false;
	}

	private function hasInvalidTimestampSuffix(string $fieldCode, string $prefix, int $expectedLength): bool
	{
		$suffix = substr($fieldCode, strlen($prefix));

		return $suffix === ''
			|| !preg_match('/^\d+$/', $suffix)
			|| strlen($suffix) !== $expectedLength;
	}

	/**
	 * Get field type for the given company field code
	 * @param string $fieldCode Company field code (e.g., COMPANY_TITLE, COMPANY_RQ_ADDR_LEGAL, etc.)
	 * @return FieldType::ADDRESS|FieldType::LIST|FieldType::DATE|FieldType::STRING
	 */
	private function getFieldType(string $fieldCode): string
	{
		if (str_starts_with($fieldCode, 'COMPANY_RQ_ADDR_'))
		{
			return FieldType::ADDRESS;
		}
		
		if (in_array($fieldCode, ['COMPANY_COMPANY_TYPE', 'COMPANY_INDUSTRY', 'COMPANY_EMPLOYEES'], true))
		{
			return FieldType::LIST;
		}
		
		if (in_array($fieldCode, ['COMPANY_LAST_ACTIVITY_TIME', 'COMPANY_RQ_COMPANY_REG_DATE'], true))
		{
			return FieldType::DATE;
		}
		
		return FieldType::STRING;
	}
}
