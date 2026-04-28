<?php

namespace Bitrix\Sign\Service\Placeholder\FieldAlias;

/**
 * Transforms field names between full and short formats
 */
class FieldNameTransformer
{
	public const FIELD_PARTS_COUNT = 3;

	/**
	 * Abbreviations where transformation differs from standard PascalCase
	 * Only non-trivial mappings that can't be derived by ucfirst(strtolower())
	 */
	private const ABBREVIATIONS = [
		'DELIVERY' => 'Del',
		'PRIMARY' => 'Prim',
		'BENEFICIARY' => 'Benef',
		'CURRENCY' => 'Cur',
		'REGISTERED' => 'Reg',
	];

	/**
	 * Special cases that don't follow standard rules
	 */
	private const SPECIAL_CASES = [
		'COMPANY_TITLE' => 'Name',
		'COMPANY_ADDRESS' => 'Address',
		'COMPANY_COMPANY_TYPE' => 'CompanyType',
		'COMPANY_IS_MY_COMPANY' => 'IsMyCompany',
		'COMPANY_ORIGIN_VERSION' => 'OriginVersion',
		'COMPANY_LAST_ACTIVITY_TIME' => 'LastActivityTime',
		'COMPANY_LAST_ACTIVITY_BY' => 'LastActivityBy',
		'COMPANY_REG_ADDRESS' => 'RegAddress',
		'COMPANY_RQ_COMPANY_NAME' => 'CompanyName',
		'COMPANY_RQ_COMPANY_FULL_NAME' => 'CompanyFullName',
		'COMPANY_RQ_COMPANY_REG_DATE' => 'RegDate',
		'COMPANY_RQ_ADDR_DELIVERY' => 'AddrDel',
		'COMPANY_RQ_ADDR_PRIMARY' => 'AddrPrim',
		'COMPANY_RQ_ADDR_REGISTERED' => 'AddrReg',
		'COMPANY_RQ_ADDR_HOME' => 'AddrHome',
		'COMPANY_RQ_ADDR_BENEFICIARY' => 'AddrBenef',
		'COMPANY_RQ_BANK_ADDR' => 'BankAddr',
		'COMPANY_RQ_ACC_CURRENCY' => 'AccCur',
		'COMPANY_RQ_COR_ACC_NUM' => 'CorAccNum',
	];

	/**
	 * Transform full field code to short alias name
	 * Example: COMPANY_RQ_INN -> Inn
	 */
	public function toShortName(string $fieldCode): string
	{
		if (isset(self::SPECIAL_CASES[$fieldCode]))
		{
			return self::SPECIAL_CASES[$fieldCode];
		}

		$withoutPrefix = $this->removeCompanyPrefix($fieldCode);
		
		if ($withoutPrefix === null)
		{
			return $fieldCode;
		}

		return $this->applyTransformationRules($withoutPrefix);
	}

	/**
	 * Transform short alias name to full field code
	 * Example: Inn -> COMPANY_RQ_INN
	 */
	public function toFullFieldCode(string $shortName): ?string
	{
		$reverseMapping = array_flip(self::SPECIAL_CASES);

		return $reverseMapping[$shortName] ?? $this->reconstructFieldCode($shortName);
	}

	/**
	 * Remove COMPANY_ or COMPANY_RQ_ prefix
	 */
	private function removeCompanyPrefix(string $fieldCode): ?string
	{
		if (str_starts_with($fieldCode, 'COMPANY_RQ_'))
		{
			return substr($fieldCode, 11);
		}
		
		if (str_starts_with($fieldCode, 'COMPANY_'))
		{
			return substr($fieldCode, 8);
		}
		
		return null;
	}

	/**
	 * Apply transformation rules to convert UPPER_SNAKE_CASE to PascalCase with abbreviations
	 * General rule: UPPER_CASE -> PascalCase (e.g., PHONE -> Phone)
	 * Exceptions: Use ABBREVIATIONS map for special cases (e.g., NUM instead of Number)
	 */
	private function applyTransformationRules(string $fieldCode): string
	{
		$parts = explode('_', $fieldCode);
		$result = [];

		foreach ($parts as $part)
		{
			if (isset(self::ABBREVIATIONS[$part]))
			{
				$result[] = self::ABBREVIATIONS[$part];
			}
			else
			{
				$result[] = ucfirst(strtolower($part));
			}
		}

		return implode('', $result);
	}

	/**
	 * Reconstruct full field code from short name
	 * Reverse transformation: Inn -> COMPANY_RQ_INN, Phone -> COMPANY_PHONE
	 */
	private function reconstructFieldCode(string $shortName): ?string
	{
		$upperSnake = $this->pascalCaseToUpperSnake($shortName);
		
		if ($upperSnake === null)
		{
			return null;
		}
		
		$withRqPrefix = 'COMPANY_RQ_' . $upperSnake;
		$withPrefix = 'COMPANY_' . $upperSnake;
		
		if ($this->looksLikeRqField($shortName))
		{
			return $withRqPrefix;
		}
		
		return $withPrefix;
	}
	
	/**
	 * Convert PascalCase to UPPER_SNAKE_CASE
	 * Example: Inn -> INN, CorAccNum -> COR_ACC_NUM
	 */
	private function pascalCaseToUpperSnake(string $pascalCase): ?string
	{
		$expanded = $this->expandAbbreviations($pascalCase);
		
		$parts = preg_split('/(?=[A-Z])/', $expanded, -1, PREG_SPLIT_NO_EMPTY);
		
		if (empty($parts))
		{
			return null;
		}
		
		$upperParts = array_map('strtoupper', $parts);
		
		return implode('_', $upperParts);
	}
	
	/**
	 * Expand abbreviations back to their full forms in the short name
	 * This helps with proper splitting of PascalCase
	 */
	private function expandAbbreviations(string $shortName): string
	{
		$abbreviationToFullWord = array_flip(self::ABBREVIATIONS);

		if (empty($abbreviationToFullWord))
		{
			return $shortName;
		}

		$abbreviations = array_keys($abbreviationToFullWord);
		
		usort($abbreviations, static fn($a, $b) => strlen($b) - strlen($a));

		$escapedAbbreviations = array_map(
			static fn($abbr) => preg_quote($abbr, '/'),
			$abbreviations,
		);
		
		$pattern = '/(^|[a-z])(' . implode('|', $escapedAbbreviations) . ')(?=[A-Z]|$)/';

		return preg_replace_callback(
			$pattern,
			static function (array $matches) use ($abbreviationToFullWord): string {
				[, $precedingChar, $abbreviation] = $matches;
				$fullWord = ucfirst(strtolower($abbreviationToFullWord[$abbreviation]));

				return $precedingChar . $fullWord;
			},
			$shortName,
		);
	}
	
	/**
	 * Heuristic to determine if field name suggests it's an RQ field
	 */
	private function looksLikeRqField(string $shortName): bool
	{
		$rqPatterns = [
			'Inn', 'Kpp', 'Ogrn', 'Okpo', 'Oktmo',
			'Bank', 'Bik', 'Acc', 'Cor', 'Swift',
			'Addr', 'Director', 'Accountant',
			'CompanyName', 'CompanyFullName', 'RegDate',
		];
		
		foreach ($rqPatterns as $pattern)
		{
			if (str_contains($shortName, $pattern))
			{
				return true;
			}
		}
		
		return false;
	}

	/**
	 * Check if field code is a company field
	 */
	public function isCompanyField(string $fieldCode): bool
	{
		return str_starts_with($fieldCode, 'COMPANY_');
	}
}
