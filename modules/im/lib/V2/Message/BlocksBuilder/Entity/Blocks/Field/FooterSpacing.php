<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field;

use Bitrix\Im\V2\Message\BlocksBuilder\BuilderError;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockType;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Footer\Spacing;
use Bitrix\Im\V2\Result;

/**
 * The gap a block keeps between its content and its footer. It stands beside the footer rather than
 * inside them on purpose: the footer is an array of rows, and turning it into an object would break
 * every client reading it today - the application among them, which this field does not concern yet.
 *
 * A step outside the set is refused rather than corrected, the way a chip design is: the set holds
 * what was looked at, and a silent fallback would hide the ask from whoever made it.
 */
class FooterSpacing extends AbstractField
{
	public function validate(mixed $field, ?BlockType $blockType = null): Result
	{
		$result = new Result();

		if ($field === null)
		{
			return $result->setResult(null);
		}

		$spacing = is_string($field) ? Spacing::tryFrom($field) : null;
		if ($spacing === null)
		{
			return $result->addError(new BuilderError(BuilderError::FOOTER_INVALID_SPACING));
		}

		return $result->setResult($spacing->value);
	}
}
