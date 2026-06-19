<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Entity\Blocks\Field;

use Bitrix\Im\V2\Message\Builder\Entity\BlockType;
use Bitrix\Im\V2\Result;

class Icon extends AbstractField
{
	public function validate(mixed $field, ?BlockType $blockType = null): Result
	{
		$result = new Result();

		if (\Bitrix\Im\V2\Message\Builder\Entity\Blocks\List\Icon::tryFrom((string)$field) === null)
		{
			$field = \Bitrix\Im\V2\Message\Builder\Entity\Blocks\List\Icon::Bullet->value;
		}

		return $result->setResult($field);
	}
}
