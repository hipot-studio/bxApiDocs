<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Entity\Blocks\Field;

use Bitrix\Im\V2\Message\Builder\BuilderError;
use Bitrix\Im\V2\Message\Builder\Entity\BlockType;
use Bitrix\Im\V2\Result;

class Text extends AbstractField
{
	public function validate(mixed $field, ?BlockType $blockType = null): Result
	{
		return match ($blockType)
		{
			BlockType::Text => $this->validateByText($field),
			BlockType::Title => $this->validateByTitle($field),
			BlockType::Map => $this->validateByMap($field),
			default => (new Result())->addError(new BuilderError(BuilderError::WRONG_BLOCK_TYPE)),
		};
	}

	protected function validateByText(mixed $field): Result
	{
		$result = new Result();

		if (!is_string($field))
		{
			return $result->addError(new BuilderError(BuilderError::INVALID_TEXT_FIELD));
		}

		if (mb_strlen($field) > self::MAX_TEXT_LENGTH)
		{
			return $result->addError(new BuilderError(BuilderError::MAX_TEXT_LENGTH));
		}

		return $result->setResult($field);
	}

	protected function validateByTitle(mixed $field): Result
	{
		$result = new Result();

		if (!is_string($field))
		{
			return $result->addError(new BuilderError(BuilderError::INVALID_TEXT_FIELD));
		}

		if (mb_strlen($field) > self::MAX_TEXT_LENGTH)
		{
			return $result->addError(new BuilderError(BuilderError::MAX_TEXT_LENGTH));
		}

		return $result->setResult($field);
	}

	protected function validateByMap(mixed $field): Result
	{
		$result = new Result();

		if ($field === null)
		{
			return $result->setResult($field);
		}

		if (!is_string($field))
		{
			return $result->addError(new BuilderError(BuilderError::INVALID_TEXT_FIELD));
		}

		if (mb_strlen($field) > self::MAX_TEXT_LENGTH)
		{
			return $result->addError(new BuilderError(BuilderError::MAX_TEXT_LENGTH));
		}

		return $result->setResult($field);
	}
}
