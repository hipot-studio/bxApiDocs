<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Entity\Blocks\Field;

use Bitrix\Im\V2\Message\Builder\BuilderError;
use Bitrix\Im\V2\Message\Builder\Entity\BlockType;
use Bitrix\Im\V2\Result;

class Size extends AbstractField
{
	public const DEFAULT_TITLE_SIZE = 1;
	public const DEFAULT_SPACE_DIVIDER_SIZE = 's';

	protected const ALLOWED_TITLE_SIZE = [1, 2];
	protected const ALLOWED_SPACE_DIVIDER_SIZE = ['s', 'm', 'l'];

	public function validate(mixed $field, ?BlockType $blockType = null): Result
	{
		return match ($blockType)
		{
			BlockType::Title => $this->validateByTitle($field),
			BlockType::SpaceDivider => $this->validateBySpaceDivider($field),
			default => (new Result())->addError(new BuilderError(BuilderError::INVALID_SIZE_FIELD)),
		};
	}

	protected function validateByTitle(mixed $field): Result
	{
		$result = new Result();

		if (!is_numeric($field))
		{
			$field = self::DEFAULT_TITLE_SIZE;
		}

		$field = (int)$field;
		if (!in_array($field, self::ALLOWED_TITLE_SIZE, true))
		{
			$field = self::DEFAULT_TITLE_SIZE;
		}

		return $result->setResult($field);
	}

	protected function validateBySpaceDivider(mixed $field): Result
	{
		$result = new Result();

		if (!in_array($field, self::ALLOWED_SPACE_DIVIDER_SIZE, true))
		{
			$field = self::DEFAULT_SPACE_DIVIDER_SIZE;
		}

		return $result->setResult($field);
	}
}
