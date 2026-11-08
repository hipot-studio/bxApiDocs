<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Measure;

use Bitrix\Crm\V2\Internal\Integration\Catalog\Measure\MeasureGateway;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * @internal
 */
class MeasureMutationService
{
	private const EDITABLE_FIELDS = [
		'MEASURE_TITLE',
		'SYMBOL_RUS',
		'SYMBOL_INTL',
		'SYMBOL_LETTER_INTL',
	];

	public function __construct(
		private readonly MeasureGateway $gateway = new MeasureGateway(),
	)
	{
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	public function update(int $id, array $fields): Result
	{
		$result = new Result();
		if ($id <= 0)
		{
			return $result->addError(new Error('Invalid measure ID.', 'INVALID_ARGUMENT'));
		}

		$validatedFields = [];
		foreach ($fields as $fieldName => $value)
		{
			if (!in_array($fieldName, self::EDITABLE_FIELDS, true))
			{
				$result->addError(new Error('Field is not editable.', 'INVALID_ARGUMENT', ['field' => $fieldName]));
				continue;
			}

			if (!is_string($value))
			{
				$result->addError(new Error('Field value must be a string.', 'INVALID_ARGUMENT', ['field' => $fieldName]));
				continue;
			}

			$validatedFields[$fieldName] = $value;
		}

		if (!$result->isSuccess())
		{
			return $result;
		}

		if ($validatedFields === [])
		{
			return $result;
		}

		return $this->gateway->update($id, $validatedFields);
	}

	public function delete(int $id): Result
	{
		if ($id <= 0)
		{
			return (new Result())->addError(new Error('Invalid measure ID.', 'INVALID_ARGUMENT'));
		}

		return $this->gateway->delete($id);
	}
}
