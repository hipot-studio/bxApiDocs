<?php

declare(strict_types=1);

namespace Bitrix\Crm\Reservation\Validator;

use Bitrix\Main\ArgumentException;

class Factory
{
	public const VALIDATOR_CUSTOM_PRODUCT_RESERVE = 'CUSTOM_PRODUCT_RESERVE';
	public const VALIDATOR_AVAILABLE_PRODUCT = 'AVAILABLE_PRODUCT';

	public static function getInstance(): static
	{
		return new static();
	}

	public function createValidator(string $validatorCode): ValidatorInterface
	{
		return match ($validatorCode)
		{
			self::VALIDATOR_AVAILABLE_PRODUCT => new AvailableProduct(),
			self::VALIDATOR_CUSTOM_PRODUCT_RESERVE => new CustomProductReserve(),
			default => throw new ArgumentException('Unknown validator code: ' . $validatorCode)
		};
	}

	/**
	 * @param array $validatorCodes
	 * @return ValidatorInterface[]
	 * @throws ArgumentException
	 */
	public function getValidatorCollection(array $validatorCodes): array
	{
		$result = [];
		foreach ($validatorCodes as $validatorCode)
		{
			$result[] = $this->createValidator($validatorCode);
		}

		return $result;
	}
}
