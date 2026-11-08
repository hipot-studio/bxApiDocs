<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Schema;

use Bitrix\Crm\V2\Internal\Entity\Settings\SettingOption;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingErrorCode;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingValidationError;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Result;

/**
 * @template TValue
 * @implements SettingSchemaInterface<list<TValue>>
 * @internal
 */
final class MultiSelectSchema implements SettingSchemaInterface
{
	/** @var list<SettingOption<TValue>> */
	private readonly array $options;
	/** @var array<string, list<TValue>> */
	private readonly array $valuesByKey;
	/** @var list<TValue> */
	private readonly array $unindexedValues;

	/**
	 * @param SettingOption<TValue> ...$options
	 * @throws ArgumentException
	 */
	public function __construct(SettingOption ...$options)
	{
		if ($options === [])
		{
			throw new ArgumentException('At least one option is required.', 'options');
		}

		$valuesByKey = [];
		$unindexedValues = [];
		foreach ($options as $option)
		{
			if (is_float($option->value) && is_nan($option->value))
			{
				continue;
			}

			$key = self::getValueKey($option->value);
			if ($key === null)
			{
				if (in_array($option->value, $unindexedValues, true))
				{
					throw new ArgumentException('Option values must be unique.', 'options');
				}

				$unindexedValues[] = $option->value;

				continue;
			}

			if (in_array($option->value, $valuesByKey[$key] ?? [], true))
			{
				throw new ArgumentException('Option values must be unique.', 'options');
			}

			$valuesByKey[$key][] = $option->value;
		}

		$this->options = $options;
		$this->valuesByKey = $valuesByKey;
		$this->unindexedValues = $unindexedValues;
	}

	/** @return list<SettingOption<TValue>> */
	public function getOptions(): array
	{
		return $this->options;
	}

	public function validate(mixed $value): Result
	{
		$result = new Result();

		if (!is_array($value) || !array_is_list($value))
		{
			return $result->addError(
				new SettingValidationError(
					'Value must be a list.',
					SettingErrorCode::INVALID_VALUE_TYPE,
				),
			);
		}

		$validatedValuesByKey = [];
		$unindexedValidatedValues = [];
		foreach ($value as $item)
		{
			if (!$this->contains($item))
			{
				return $result->addError(
					new SettingValidationError(
						'Value contains an item that is not one of the allowed options.',
						SettingErrorCode::VALUE_NOT_ALLOWED,
					),
				);
			}

			$key = self::getValueKey($item);
			$validatedValues = $key === null
				? $unindexedValidatedValues
				: $validatedValuesByKey[$key] ?? []
			;
			if (in_array($item, $validatedValues, true))
			{
				return $result->addError(
					new SettingValidationError(
						'Value must not contain duplicate items.',
						SettingErrorCode::DUPLICATE_VALUE,
					),
				);
			}

			if ($key === null)
			{
				$unindexedValidatedValues[] = $item;
			}
			else
			{
				$validatedValuesByKey[$key][] = $item;
			}
		}

		return $result;
	}

	/** @param TValue $value */
	private function contains(mixed $value): bool
	{
		if (is_float($value) && is_nan($value))
		{
			return false;
		}

		$key = self::getValueKey($value);
		if ($key === null)
		{
			return in_array($value, $this->unindexedValues, true);
		}

		return in_array($value, $this->valuesByKey[$key] ?? [], true);
	}

	private static function getValueKey(mixed $value): ?string
	{
		if (is_float($value))
		{
			if ($value === 0.0)
			{
				return 'float:zero';
			}
		}

		if (is_object($value))
		{
			return 'object:' . spl_object_id($value);
		}

		if (is_resource($value))
		{
			return null;
		}

		if (is_array($value) || gettype($value) === 'resource (closed)')
		{
			return null;
		}

		return get_debug_type($value) . ':' . serialize($value);
	}
}
