<?php

declare(strict_types=1);

namespace Bitrix\Crm\Dto\Validator;

use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator;
use Bitrix\Crm\Result;
use Bitrix\Main\Error;

final class StringLengthField extends Validator
{
	public function __construct(
		Dto $dto,
		private readonly string $field,
		private readonly ?int $min = null,
		private readonly ?int $max = null,
	)
	{
		parent::__construct($dto);
	}

	public function validate(array $fields): Result
	{
		if (!isset($fields[$this->field]) || !is_string($fields[$this->field]))
		{
			return Result::success();
		}

		$length = mb_strlen($fields[$this->field]);
		if (
			($this->min !== null && $length < $this->min)
			|| ($this->max !== null && $length > $this->max)
		)
		{
			return Result::fail($this->error());
		}

		return Result::success();
	}

	private function error(): Error
	{
		$bounds = match (true)
		{
			$this->min !== null && $this->max !== null => "between {$this->min} and {$this->max}",
			$this->min !== null => "at least {$this->min}",
			default => "at most {$this->max}",
		};

		return new Error(
			message: "Field {$this->field} in {$this->dto->getName()} must contain {$bounds} characters.",
			code: 'STRING_LENGTH',
			customData: [
				'FIELD' => $this->field,
				'PARENT_OBJECT' => $this->dto->getName(),
				'MIN_LENGTH' => $this->min,
				'MAX_LENGTH' => $this->max,
			],
		);
	}
}
