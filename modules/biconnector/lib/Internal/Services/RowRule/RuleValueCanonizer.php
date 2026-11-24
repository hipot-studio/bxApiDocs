<?php

namespace Bitrix\BIConnector\Internal\Services\RowRule;

use Bitrix\BIConnector\DataSource\FieldKind;
use Bitrix\BIConnector\Internal\Model\RowRuleTable;
use Bitrix\BIConnector\Internal\Repository\Mapper\RowRuleMapper;
use Bitrix\BIConnector\Superset\Error\UserFacingError;
use Bitrix\Main\Result;

/**
 * Checks the fields of a row rule and brings its values to the stored canon.
 *
 * Values are checked against the current table description: type, kind and owner of a field are never taken
 * from the request. A comparison key is built for each value, so duplicates are found the same way for a request
 * and for a stored rule.
 */
final class RuleValueCanonizer
{
	public const OPERATOR_EQUAL = 'equal';
	public const OPERATOR_IN = 'in';

	public const MAX_VALUES = 500;
	public const MAX_VALUE_DATA_BYTES = 48000;
	public const MAX_DESCRIPTION_LENGTH = 2000;

	public const STORED_CONSTANT = 'c';

	private const ITEM_TOKEN = 'token';
	private const ITEM_CONSTANT = 'constant';

	private const REQUIRED_FIELDS = ['tableCode', 'fieldCode', 'operator', 'values'];

	private readonly RowRuleMapper $mapper;

	public function __construct(?RowRuleMapper $mapper = null)
	{
		$this->mapper = $mapper ?? new RowRuleMapper();
	}

	/**
	 * @param array $fields `tableCode`, `fieldCode`, `operator`, `values`, `description` of the API.
	 * @param FieldDescriptorReader $reader
	 *
	 * @return Result Data: `values` - canonical stored items, `keys` - their comparison keys,
	 *     `valueData` - the stored JSON, `description` - null when empty; or the first error of the contract.
	 */
	public function validate(array $fields, FieldDescriptorReader $reader): Result
	{
		$result = new Result();

		foreach (self::REQUIRED_FIELDS as $name)
		{
			if ($this->isMissing($name, $fields[$name] ?? null))
			{
				return $result->addError(RowRuleError::create(RowRuleError::REQUIRED, ['field' => $name]));
			}
		}

		$tableCode = (string)$fields['tableCode'];
		if (!$reader->hasTable($tableCode))
		{
			return $result->addError(RowRuleError::create(RowRuleError::TABLE_NOT_FOUND, ['field' => 'tableCode']));
		}

		$field = $reader->getField($tableCode, (string)$fields['fieldCode']);
		if ($field === null || !$field->isSupported)
		{
			return $result->addError(RowRuleError::create(RowRuleError::FIELD_NOT_FOUND, ['field' => 'fieldCode']));
		}

		$requestValues = array_values($fields['values']);
		if (!$this->isOperatorValid($fields['operator'], count($requestValues)))
		{
			return $result->addError(RowRuleError::create(RowRuleError::OPERATOR_INVALID, ['field' => 'operator']));
		}

		$itemsResult = $this->canonizeItems($field, $requestValues);
		if (!$itemsResult->isSuccess())
		{
			return $result->addErrors($itemsResult->getErrors());
		}

		['values' => $values, 'keys' => $keys] = $itemsResult->getData();

		$description = $fields['description'] ?? null;
		$description = is_scalar($description) && (string)$description !== '' ? (string)$description : null;
		if ($description !== null && !mb_check_encoding($description, 'UTF-8'))
		{
			return $result->addError(RowRuleError::create(RowRuleError::VALUE_INVALID, ['field' => 'description']));
		}

		if (count($values) > self::MAX_VALUES)
		{
			return $result->addError(RowRuleError::create(
				RowRuleError::TOO_MANY_VALUES,
				['field' => 'values', 'limit' => self::MAX_VALUES],
			));
		}

		$valueData = $this->mapper->encodeValues($values);
		if ($this->getStoredLength($valueData) > self::MAX_VALUE_DATA_BYTES)
		{
			return $result->addError(RowRuleError::create(
				RowRuleError::TOO_LARGE,
				['field' => 'values', 'limit' => self::MAX_VALUE_DATA_BYTES],
			));
		}

		if ($description !== null && mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH)
		{
			return $result->addError(RowRuleError::create(
				RowRuleError::DESCRIPTION_TOO_LONG,
				['field' => 'description', 'limit' => self::MAX_DESCRIPTION_LENGTH],
			));
		}

		return $result->setData([
			'values' => $values,
			'keys' => $keys,
			'valueData' => $valueData,
			'description' => $description,
		]);
	}

	/**
	 * Whether a stored item still fits the field as the table describes it now.
	 *
	 * @param FieldDescriptor $field
	 * @param array $storedItem `['k' => string, 'v' => ?string]`.
	 *
	 * @return bool
	 */
	public function isValueAcceptable(FieldDescriptor $field, array $storedItem): bool
	{
		$storedKey = $storedItem['k'] ?? null;
		if ($storedKey === self::STORED_CONSTANT)
		{
			$value = $storedItem['v'] ?? null;

			return is_string($value) && $this->canonizeConstant($field, $value) !== null;
		}

		$code = is_string($storedKey) ? TokenDictionary::fromStored($storedKey) : null;

		return $code !== null && in_array($code, TokenDictionary::getAllowed($field->kind), true);
	}

	/**
	 * Comparison keys of stored items, built by the current description of the field.
	 *
	 * @param FieldDescriptor $field
	 * @param array $storedValues
	 *
	 * @return string[] Unique keys, sorted.
	 */
	public function getStoredKeys(FieldDescriptor $field, array $storedValues): array
	{
		$keys = [];
		foreach ($storedValues as $item)
		{
			$storedKey = is_array($item) ? ($item['k'] ?? null) : null;
			if (!is_string($storedKey))
			{
				continue;
			}

			if ($storedKey === self::STORED_CONSTANT)
			{
				$value = (string)($item['v'] ?? '');
				$keys[] = $this->getConstantKey($field, $this->canonizeConstant($field, $value) ?? $value);
			}
			else
			{
				$keys[] = TokenDictionary::fromStored($storedKey) ?? $storedKey;
			}
		}

		$keys = array_values(array_unique($keys));
		sort($keys, SORT_STRING);

		return $keys;
	}

	/**
	 * Bytes of the value data as the column receives them: the save modifiers of the field store every 4-byte
	 * character as ten bytes of `:hex:`.
	 */
	private function getStoredLength(string $valueData): int
	{
		return strlen((string)RowRuleTable::getEntity()->getField('VALUE_DATA')->modifyValueBeforeSave($valueData, []));
	}

	private function isMissing(string $name, mixed $value): bool
	{
		if ($name === 'values')
		{
			return !is_array($value) || $value === [];
		}

		return !is_scalar($value) || trim((string)$value) === '';
	}

	private function isOperatorValid(mixed $operator, int $valueCount): bool
	{
		return match ($operator)
		{
			self::OPERATOR_EQUAL => $valueCount === 1,
			self::OPERATOR_IN => true,
			default => false,
		};
	}

	/**
	 * @param FieldDescriptor $field
	 * @param array $requestValues Items of the API: `{type: token, code}` or `{type: constant, value}`.
	 *
	 * @return Result Data: `values` and `keys` without repeats, tokens first, then constants by key.
	 */
	private function canonizeItems(FieldDescriptor $field, array $requestValues): Result
	{
		$result = new Result();
		$allowedTokens = TokenDictionary::getAllowed($field->kind);
		$tokenErrorIndex = null;
		$tokens = [];
		$constants = [];

		foreach ($requestValues as $index => $item)
		{
			$type = is_array($item) ? ($item['type'] ?? null) : null;
			if ($type === self::ITEM_CONSTANT)
			{
				$value = $item['value'] ?? null;
				$canon = is_string($value) || is_int($value)
					? $this->canonizeConstant($field, (string)$value)
					: null
				;
				if ($canon === null)
				{
					return $result->addError($this->createValueError(RowRuleError::VALUE_INVALID, $index));
				}

				$constants[$this->getConstantKey($field, $canon)] ??= $canon;
			}
			elseif ($type === self::ITEM_TOKEN)
			{
				$code = $item['code'] ?? null;
				if (!is_string($code) || !in_array($code, $allowedTokens, true))
				{
					$tokenErrorIndex ??= $index;

					continue;
				}

				$tokens[$code] = TokenDictionary::toStored($code);
			}
			else
			{
				return $result->addError($this->createValueError(RowRuleError::VALUE_INVALID, $index));
			}
		}

		if ($tokenErrorIndex !== null)
		{
			return $result->addError($this->createValueError(RowRuleError::TOKEN_NOT_ALLOWED, $tokenErrorIndex));
		}

		uasort(
			$tokens,
			static fn(string $a, string $b): int => TokenDictionary::getOrder($a) <=> TokenDictionary::getOrder($b),
		);
		ksort($constants, SORT_STRING);

		$values = [];
		foreach ($tokens as $storedKey)
		{
			$values[] = ['k' => $storedKey];
		}
		foreach ($constants as $canon)
		{
			$values[] = ['k' => self::STORED_CONSTANT, 'v' => $canon];
		}

		return $result->setData([
			'values' => $values,
			'keys' => array_map('strval', [...array_keys($tokens), ...array_keys($constants)]),
		]);
	}

	private function createValueError(string $code, int $index): UserFacingError
	{
		return RowRuleError::create($code, ['field' => 'values', 'index' => $index]);
	}

	/**
	 * @return string|null The canonical spelling, or null when the value does not fit the field.
	 */
	private function canonizeConstant(FieldDescriptor $field, string $value): ?string
	{
		if (trim($value) === '' || !mb_check_encoding($value, 'UTF-8'))
		{
			return null;
		}

		if ($field->kind === FieldKind::EMPLOYEE || $field->kind === FieldKind::DEPARTMENT)
		{
			$canon = preg_match('/^\d+$/D', $value) ? $this->trimLeadingZeros($value) : null;

			return $canon === '0' || !$this->fitsPhpInt($canon) ? null : $canon;
		}

		return match ($field->type)
		{
			'int' => $this->canonizeInt($value),
			'double' => $this->canonizeDouble($value),
			'date' => $this->isDateOfFormat($value, 'Y-m-d') ? $value : null,
			'datetime' => $this->isDateOfFormat($value, 'Y-m-d H:i:s') ? $value : null,
			default => $value,
		};
	}

	private function canonizeInt(string $value): ?string
	{
		if (!preg_match('/^(-?)(\d+)$/D', $value, $matches))
		{
			return null;
		}

		$canon = $this->applySign($matches[1], $this->trimLeadingZeros($matches[2]));

		return $this->fitsPhpInt($canon) ? $canon : null;
	}

	/**
	 * Whether a canonical integer spelling stays within PHP_INT_MIN...PHP_INT_MAX: a cast of a wider number saturates,
	 * so its spelling no longer matches.
	 */
	private function fitsPhpInt(?string $canon): bool
	{
		return $canon !== null && (string)(int)$canon === $canon;
	}

	private function canonizeDouble(string $value): ?string
	{
		if (!preg_match('/^(-?)(\d+)(?:\.(\d+))?$/D', $value, $matches))
		{
			return null;
		}

		$number = $this->trimLeadingZeros($matches[2]);
		$fraction = rtrim($matches[3] ?? '', '0');
		if ($fraction !== '')
		{
			$number .= '.' . $fraction;
		}

		return $this->applySign($matches[1], $number);
	}

	private function trimLeadingZeros(string $digits): string
	{
		$trimmed = ltrim($digits, '0');

		return $trimmed === '' ? '0' : $trimmed;
	}

	private function applySign(string $sign, string $number): string
	{
		return $number === '0' ? $number : $sign . $number;
	}

	private function isDateOfFormat(string $value, string $format): bool
	{
		$date = \DateTimeImmutable::createFromFormat('!' . $format, $value);

		return $date !== false && $date->format($format) === $value;
	}

	private function getConstantKey(FieldDescriptor $field, string $canon): string
	{
		$isFreeString = $field->kind === '' && $field->type === 'string';

		return $isFreeString ? mb_strtolower($canon) : $canon;
	}
}
