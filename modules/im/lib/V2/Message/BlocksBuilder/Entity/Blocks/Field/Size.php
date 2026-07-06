<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field;

use Bitrix\Im\V2\Message\BlocksBuilder\BuilderError;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockType;
use Bitrix\Im\V2\Result;

class Size extends AbstractField
{
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
			$field = Title\Size::Small->value;
		}

		$field = (int)$field;
		if (Title\Size::tryFrom($field) === null)
		{
			$field = Title\Size::Small->value;
		}

		return $result->setResult($field);
	}

	protected function validateBySpaceDivider(mixed $field): Result
	{
		$result = new Result();

		if (SpaceDivider\Size::tryFrom((string)$field) === null)
		{
			$field = SpaceDivider\Size::Small->value;
		}

		return $result->setResult($field);
	}
}
