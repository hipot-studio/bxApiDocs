<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Schema;

use Bitrix\Crm\V2\Internal\Service\Settings\SettingErrorCode;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingValidationError;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Result;

/**
 * @implements SettingSchemaInterface<int>
 * @internal
 */
final class IntegerSchema implements SettingSchemaInterface
{
	/** @throws ArgumentException */
	public function __construct(
		private readonly ?int $min = null,
		private readonly ?int $max = null,
		private readonly int $step = 1,
	)
	{
		if ($this->min !== null && $this->max !== null && $this->min > $this->max)
		{
			throw new ArgumentException('Minimum must not be greater than maximum.', 'min');
		}

		if ($this->step < 1)
		{
			throw new ArgumentException('Step must be greater than zero.', 'step');
		}
	}

	public function getMin(): ?int
	{
		return $this->min;
	}

	public function getMax(): ?int
	{
		return $this->max;
	}

	public function getStep(): int
	{
		return $this->step;
	}

	public function validate(mixed $value): Result
	{
		$result = new Result();

		if (!is_int($value))
		{
			return $result->addError(
				new SettingValidationError(
					'Value must be an integer.',
					SettingErrorCode::INVALID_VALUE_TYPE,
				),
			);
		}

		if (
			($this->min !== null && $value < $this->min)
			|| ($this->max !== null && $value > $this->max)
		)
		{
			return $result->addError(
				new SettingValidationError(
					'Value is outside the allowed range.',
					SettingErrorCode::VALUE_OUT_OF_RANGE,
				),
			);
		}

		$stepBase = $this->min ?? 0;
		if ($this->normalizedRemainder($value) !== $this->normalizedRemainder($stepBase))
		{
			$result->addError(
				new SettingValidationError(
					'Value does not match the required step.',
					SettingErrorCode::VALUE_STEP_MISMATCH,
				),
			);
		}

		return $result;
	}

	private function normalizedRemainder(int $value): int
	{
		$remainder = $value % $this->step;

		return $remainder < 0 ? $remainder + $this->step : $remainder;
	}
}
