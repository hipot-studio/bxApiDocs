<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field;

use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockType;
use Bitrix\Im\V2\Result;

class Service extends AbstractField
{
	public function validate(mixed $field, ?BlockType $blockType = null): Result
	{
		return (new Result())->setResult(self::validateBoolValue($field));
	}
}
