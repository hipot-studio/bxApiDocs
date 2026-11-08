<?php

namespace Bitrix\Crm\Import\Strategy\ValueMapper;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use CCrmUserType;
use CUserFieldEnum;

final class UserTypeValueMapper
{
	private const MULTIPLE_VALUE_DELIMITER = ',';

	private static array $enumerationCache = [];

	public function __construct(
		private readonly string $fieldId,
		private readonly CCrmUserType $userType,
		private readonly ?array $userFieldInfo = null,
		private readonly ?\Closure $enumerationProvider = null,
	)
	{
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		$columnIndex = $fieldBindings->getColumnIndexByFieldId($this->fieldId);
		if ($columnIndex === null)
		{
			return FieldProcessResult::skip();
		}

		$value = $row[$columnIndex] ?? null;
		if (!$this->hasImportValue($value))
		{
			return FieldProcessResult::skip();
		}

		$importItemFields[$this->fieldId] = $this->internalizeValue($value);

		return FieldProcessResult::success();
	}

	private function hasImportValue(mixed $value): bool
	{
		if ($this->isEnumerationField())
		{
			return ValuePresence::isPresent($value);
		}

		return !empty($value);
	}

	private function internalizeValue(mixed $value): mixed
	{
		if ($this->isMultipleEnumerationField())
		{
			return $this->internalizeMultipleEnumeration($value);
		}

		return $this->internalize($value);
	}

	private function internalize(mixed $value): mixed
	{
		return $this->userType->Internalize(
			$this->fieldId,
			$value,
			self::MULTIPLE_VALUE_DELIMITER,
			$this->userFieldInfo,
		);
	}

	private function internalizeMultipleEnumeration(mixed $value): array
	{
		$enumerations = $this->getEnumerationValues();
		if (ValuePresence::hasLineBreakDelimiter($value))
		{
			$enumId = $this->resolveEnumerationId($value, $enumerations);
			if ($enumId !== null)
			{
				return [$enumId];
			}
		}

		$result = [];
		foreach (ValuePresence::normalizeMultipleImportValue($value, self::MULTIPLE_VALUE_DELIMITER) as $item)
		{
			$enumId = $this->resolveEnumerationId($item, $enumerations);
			if ($enumId !== null)
			{
				$result[] = $enumId;
			}
		}

		return $result;
	}

	private function getEnumerationValues(): array
	{
		$userFieldId = (int)($this->userFieldInfo['ID'] ?? 0);
		if ($userFieldId <= 0)
		{
			return [];
		}

		if ($this->enumerationProvider !== null)
		{
			return ($this->enumerationProvider)($userFieldId);
		}

		if (!array_key_exists($userFieldId, self::$enumerationCache))
		{
			self::$enumerationCache[$userFieldId] = [];
			$enumResult = CUserFieldEnum::GetList([], ['USER_FIELD_ID' => $userFieldId]);
			while ($enum = $enumResult->Fetch())
			{
				self::$enumerationCache[$userFieldId][$enum['ID']] = $enum;
			}
		}

		return self::$enumerationCache[$userFieldId];
	}

	private function resolveEnumerationId(mixed $value, array $enumerations): int|string|null
	{
		if (isset($enumerations[$value]))
		{
			return $value;
		}

		$normalizedValue = mb_strtoupper(trim((string)$value));
		foreach ($enumerations as $enumId => $enumeration)
		{
			$enumValue = (string)($enumeration['VALUE'] ?? '');
			$xmlId = $enumeration['XML_ID'] ?? null;
			if (mb_strtoupper($enumValue) === $normalizedValue || ($xmlId !== null && $xmlId === $value))
			{
				return $enumId;
			}
		}

		return null;
	}

	private function isMultipleEnumerationField(): bool
	{
		return
			$this->isEnumerationField()
			&& ($this->userFieldInfo['MULTIPLE'] ?? null) === 'Y';
	}

	private function isEnumerationField(): bool
	{
		return
			$this->userFieldInfo !== null
			&& ($this->userFieldInfo['USER_TYPE']['USER_TYPE_ID'] ?? null) === 'enumeration';
	}
}
