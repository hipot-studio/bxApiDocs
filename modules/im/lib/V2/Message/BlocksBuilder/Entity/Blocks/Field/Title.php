<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field;

use Bitrix\Im\V2\Message\BlocksBuilder\BuilderError;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockType;
use Bitrix\Im\V2\Result;

class Title extends AbstractField
{
	public function validate(mixed $field, ?BlockType $blockType = null): Result
	{
		$result = new Result();

		if (!is_string($field))
		{
			return $result->addError(new BuilderError(BuilderError::INVALID_TITLE_FIELD));
		}

		if ($field === '')
		{
			return $result->addError(new BuilderError(BuilderError::EMPTY_TITLE_FIELD));
		}

		return $result->setResult($field);
	}
}
