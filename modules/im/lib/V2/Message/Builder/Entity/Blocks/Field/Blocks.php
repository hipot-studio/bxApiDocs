<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Entity\Blocks\Field;

use Bitrix\Im\V2\Message\Builder\BuilderError;
use Bitrix\Im\V2\Message\Builder\Entity\BlockType;
use Bitrix\Im\V2\Result;

class Blocks extends AbstractField
{
	protected const MAX_BLOCKS = 100;

	public function validate(mixed $field, ?BlockType $blockType = null): Result
	{
		$result = new Result();

		if (empty($field))
		{
			return $result->addError((new BuilderError(BuilderError::EMPTY_BLOCKS)));
		}

		if (!is_array($field))
		{
			return $result->addError((new BuilderError(BuilderError::INVALID_BLOCKS_FIELD)));
		}

		if (count($field) > self::MAX_BLOCKS)
		{
			return $result->addError((new BuilderError(BuilderError::MAX_BLOCKS_ERROR)));
		}

		return $result->setResult($field);
	}
}
