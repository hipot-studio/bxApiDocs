<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Validator;

use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator;
use Bitrix\Crm\Result;

final class HexColorField extends Validator
{
	public const PATTERN = '^#[0-9A-Fa-f]{6}$';

	public function __construct(
		Dto $dto,
		private readonly string $field,
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

		if (preg_match('~' . self::PATTERN . '~D', $fields[$this->field]) !== 1)
		{
			return Result::fail(
				"Field {$this->field} in {$this->dto->getName()} must be a color in #RRGGBB format."
			);
		}

		return Result::success();
	}
}
