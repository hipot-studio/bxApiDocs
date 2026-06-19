<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Entity\Blocks\Field;

use Bitrix\Im\V2\Message\Builder\Entity\BlockType;
use Bitrix\Im\V2\Result;

class Color extends AbstractField
{
	public function validate(mixed $field, ?BlockType $blockType = null): Result
	{
		$result = new Result();

		if (\Bitrix\Im\V2\Message\Color\Color::tryFrom((string)$field) === null)
		{
			$field = \Bitrix\Im\V2\Message\Color\Color::BASE->value;
		}

		return $result->setResult($field);
	}
}
