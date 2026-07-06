<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field;

use Bitrix\Im\V2\Message\BlocksBuilder\BuilderError;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockType;
use Bitrix\Im\V2\Result;

class Status extends AbstractField
{
	public function validate(mixed $field, ?BlockType $blockType = null): Result
	{
		$result = new Result();

		if ($field === null || $field === '')
		{
			return $result->setResult(null);
		}

		if (!is_string($field))
		{
			return $result->addError(new BuilderError(BuilderError::INVALID_STATUS_FIELD));
		}

		return $result->setResult($field);
	}
}
