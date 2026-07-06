<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field;

use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\List\IconType;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockType;
use Bitrix\Im\V2\Result;

class Icon extends AbstractField
{
	public function validate(mixed $field, ?BlockType $blockType = null): Result
	{
		$result = new Result();

		if (!is_array($field))
		{
			return $result->setResult([]);
		}

		$icon = (string)($field['type'] ?? '');
		if (IconType::tryFrom($icon) === null)
		{
			$icon = IconType::Bullet->value;
		}

		$color = (string)($field['color'] ?? '');
		if (!\Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Color\Color::checkForIcon($color))
		{
			$color = null;
		}

		return $result->setResult([
			'type' => $icon,
			'color' => $color,
		]);
	}
}
