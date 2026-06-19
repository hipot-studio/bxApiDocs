<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Entity\Blocks\Field;

use Bitrix\Im\V2\Message\Builder\BuilderError;
use Bitrix\Im\V2\Message\Builder\Entity\BlockType;
use Bitrix\Im\V2\Result;

class Type extends AbstractField
{
	public function validate(mixed $field, ?BlockType $blockType = null): Result
	{
		$result = new Result();

		if (empty($field))
		{
			return $result->addError((new BuilderError(BuilderError::EMPTY_REQUIRED_FIELD)));
		}

		if (!is_string($field))
		{
			return $result->addError((new BuilderError(BuilderError::INVALID_TYPE_FIELD)));
		}

		return $result->setResult($field);
	}
}
