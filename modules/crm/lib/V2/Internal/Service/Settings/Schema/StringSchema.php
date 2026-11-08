<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Schema;

use Bitrix\Crm\V2\Internal\Service\Settings\SettingErrorCode;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingValidationError;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Result;

/**
 * @implements SettingSchemaInterface<string>
 * @internal
 */
final class StringSchema implements SettingSchemaInterface
{
	/** @throws ArgumentException */
	public function __construct(
		private readonly ?int $minLength = null,
		private readonly ?int $maxLength = null,
	)
	{
		if ($this->minLength !== null && $this->minLength < 0)
		{
			throw new ArgumentException('Minimum length must not be negative.', 'minLength');
		}

		if ($this->maxLength !== null && $this->maxLength < 0)
		{
			throw new ArgumentException('Maximum length must not be negative.', 'maxLength');
		}

		if (
			$this->minLength !== null
			&& $this->maxLength !== null
			&& $this->minLength > $this->maxLength
		)
		{
			throw new ArgumentException(
				'Minimum length must not be greater than maximum length.',
				'minLength',
			);
		}
	}

	public function getMinLength(): ?int
	{
		return $this->minLength;
	}

	public function getMaxLength(): ?int
	{
		return $this->maxLength;
	}

	public function validate(mixed $value): Result
	{
		$result = new Result();

		if (!is_string($value))
		{
			return $result->addError(
				new SettingValidationError(
					'Value must be a string.',
					SettingErrorCode::INVALID_VALUE_TYPE,
				),
			);
		}

		$length = mb_strlen($value);
		if (
			($this->minLength !== null && $length < $this->minLength)
			|| ($this->maxLength !== null && $length > $this->maxLength)
		)
		{
			$result->addError(
				new SettingValidationError(
					'String length is outside the allowed range.',
					SettingErrorCode::VALUE_OUT_OF_RANGE,
				),
			);
		}

		return $result;
	}
}
