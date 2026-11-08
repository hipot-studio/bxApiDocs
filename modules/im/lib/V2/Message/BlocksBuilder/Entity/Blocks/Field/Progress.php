<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field;

use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockType;
use Bitrix\Im\V2\Result;

class Progress extends AbstractField
{
	protected const MIN_VALUE = 0;
	protected const MAX_VALUE = 100;

	public function validate(mixed $field, ?BlockType $blockType = null): Result
	{
		$result = new Result();

		if (!is_array($field))
		{
			return $result->setResult(null);
		}

		if (!array_key_exists('value', $field) || !is_numeric($field['value']))
		{
			return $result->setResult(null);
		}

		$progress = ['value' => $this->normalizeValue($field['value'])];

		// the color token is optional for clients, so an absent one leaves no key behind
		$colorToken = $this->normalizeColorToken($field['colorToken'] ?? null);
		if ($colorToken !== null)
		{
			$progress['colorToken'] = $colorToken;
		}

		return $result->setResult($progress);
	}

	protected function normalizeValue(mixed $value): int
	{
		return max(self::MIN_VALUE, min(self::MAX_VALUE, (int)$value));
	}

	protected function normalizeColorToken(mixed $colorToken): ?string
	{
		if ($colorToken === null)
		{
			return null;
		}

		$token = is_string($colorToken) ? $colorToken : '';

		return Color\Color::tryFrom($token)?->value ?? Color\Color::Base->value;
	}
}
