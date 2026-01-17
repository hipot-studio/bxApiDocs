<?php

declare(strict_types=1);

namespace Bitrix\AI\Tokenizer;

class GigaChat implements TokenizerInterface
{
	private const AVERAGE_TOKEN_LENGTH = 3.5;

	public function count(string $text): int
	{
		$length = mb_strlen($text, 'UTF-8');
		return (int)ceil($length / self::AVERAGE_TOKEN_LENGTH);
	}
}
