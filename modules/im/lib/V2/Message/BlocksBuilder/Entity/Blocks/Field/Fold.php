<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field;

use Bitrix\Im\V2\Message\BlocksBuilder\BuilderError;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockType;
use Bitrix\Im\V2\Result;

class Fold extends AbstractField
{
	public function validate(mixed $field, ?BlockType $blockType = null): Result
	{
		$result = new Result();

		if (!is_array($field))
		{
			return $result->setResult(null);
		}

		if (empty($field['title']) || !is_string($field['title']))
		{
			return $result->addError(new BuilderError(BuilderError::INVALID_TITLE_FIELD));
		}

		$field['isOpened'] = self::validateBoolValue($field['isOpened'] ?? false);

		return $result->setResult($field);
	}
}
