<?php

namespace Bitrix\Sign\Service\Integration\Crm;

final class CompanyRequisiteFieldPolicy
{
	private const COMPANY_CATEGORY = 'COMPANY';
	private const REQUISITE_PREFIX = 'RQ_';
	private const STRING_TYPE = 'string';
	private const EXECUTIVE_FIELD_CODES = [
		'RQ_DIRECTOR',
		'RQ_CEO_NAME',
		'RQ_CEO_WORK_POS',
	];

	/**
	 * Filters company requisite fields without changing the selector tree structure.
	 *
	 * @param list<string> $fieldSetCodes
	 */
	public function filterSelectorTree(array $selectorTree, array $fieldSetCodes): array
	{
		$companyFields = $selectorTree[self::COMPANY_CATEGORY]['FIELDS'] ?? null;
		if (!is_array($companyFields))
		{
			return $selectorTree;
		}

		$selectorTree[self::COMPANY_CATEGORY]['FIELDS'] = array_values(
			array_filter(
				$companyFields,
				function (mixed $field) use ($fieldSetCodes): bool {
					if (!is_array($field) || !$this->isRequisiteDescription($field))
					{
						return true;
					}

					return $this->isFieldAllowed($field, $fieldSetCodes);
				},
			),
		);

		return $selectorTree;
	}

	/**
	 * Checks whether a CRM field description allows reading the requested field code.
	 *
	 * @param list<string> $fieldSetCodes
	 */
	public function isFieldAllowed(
		array $fieldDescription,
		array $fieldSetCodes,
		?string $fieldCode = null,
	): bool
	{
		$descriptionCode = $this->getFieldCode($fieldDescription);
		$requestedCode = $this->normalizeFieldCode($fieldCode ?? $descriptionCode);

		if (
			$descriptionCode === null
			|| $requestedCode === null
			|| $requestedCode !== $descriptionCode
			|| ($fieldDescription['entity_name'] ?? null) !== self::COMPANY_CATEGORY
			|| ($fieldDescription['type'] ?? null) !== self::STRING_TYPE
			|| ($fieldDescription['multiple'] ?? null) !== false
			|| preg_match('/^RQ_[A-Z0-9_]+$/D', $requestedCode) !== 1
		)
		{
			return false;
		}

		return $this->isFieldCodeAllowed($requestedCode, $fieldSetCodes);
	}

	/**
	 * Checks product policy for a normalized requisite field code.
	 *
	 * @param list<string> $fieldSetCodes
	 */
	public function isFieldCodeAllowed(string $fieldCode, array $fieldSetCodes): bool
	{
		$fieldCode = $this->normalizeFieldCode($fieldCode);
		if ($fieldCode === null || preg_match('/^RQ_[A-Z0-9_]+$/D', $fieldCode) !== 1)
		{
			return false;
		}

		return in_array($fieldCode, self::EXECUTIVE_FIELD_CODES, true)
			|| in_array($fieldCode, $this->normalizeFieldSetCodes($fieldSetCodes), true);
	}

	private function isRequisiteDescription(array $fieldDescription): bool
	{
		$fieldCode = $this->getFieldCode($fieldDescription);

		return $fieldCode !== null && str_starts_with($fieldCode, self::REQUISITE_PREFIX);
	}

	private function getFieldCode(array $fieldDescription): ?string
	{
		$fieldCode = $fieldDescription['entity_field_name'] ?? null;
		if (!is_string($fieldCode) || $fieldCode === '')
		{
			$fieldCode = $fieldDescription['name'] ?? null;
		}

		return is_string($fieldCode) ? $this->normalizeFieldCode($fieldCode) : null;
	}

	private function normalizeFieldCode(?string $fieldCode): ?string
	{
		if ($fieldCode === null)
		{
			return null;
		}

		if (str_starts_with($fieldCode, self::COMPANY_CATEGORY . '_'))
		{
			$fieldCode = substr($fieldCode, strlen(self::COMPANY_CATEGORY) + 1);
		}

		return $fieldCode !== '' ? $fieldCode : null;
	}

	/**
	 * @param list<string> $fieldSetCodes
	 * @return list<string>
	 */
	private function normalizeFieldSetCodes(array $fieldSetCodes): array
	{
		$normalizedCodes = [];
		foreach ($fieldSetCodes as $fieldSetCode)
		{
			if (!is_string($fieldSetCode))
			{
				continue;
			}

			$normalizedCode = $this->normalizeFieldCode($fieldSetCode);
			if ($normalizedCode !== null)
			{
				$normalizedCodes[] = $normalizedCode;
			}
		}

		return array_values(array_unique($normalizedCodes));
	}
}
