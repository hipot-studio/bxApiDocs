<?php

namespace Bitrix\Crm\Dto\Validator;

use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Crm\Result;
use Bitrix\Main\Type\DateTime;

final class DateTimeField extends Validator
{
	public function __construct(
		Dto $dto,
		protected string $format,
		protected string $fieldToCheck,
	)
	{
		parent::__construct($dto);
	}

	public function validate(array $fields): Result
	{
		if (!array_key_exists($this->fieldToCheck, $fields))
		{
			return Result::success();
		}

		$value = $fields[$this->fieldToCheck];
		if (is_string($value) && DateTime::isCorrect($value, $this->format))
		{
			return Result::success();
		}

		return Result::fail($this->error());
	}

	private function error(): Error
	{
		return new Error(
			message: Loc::getMessage('CRM_DTO_VALIDATOR_DATETIME_FIELD', [
				'#FIELD#' => $this->fieldToCheck,
				'#PARENT_OBJECT#' => $this->dto->getName(),
				'#FORMAT#' => $this->format,
			]),
			code: 'DATETIME_FIELD',
			customData: [
				'FIELD' => $this->fieldToCheck,
				'PARENT_OBJECT' => $this->dto->getName(),
				'FORMAT' => $this->format,
			],
		);
	}
}
