<?php

declare(strict_types=1);

namespace Bitrix\AI\Tokenizer;

class YandexGPT implements TokenizerInterface
{
	private const AVERAGE_TOKEN_LENGTH = 4;

	public function count(string $text): int
	{
		$length = mb_strlen($text, 'UTF-8');
		return (int)ceil($length / self::AVERAGE_TOKEN_LENGTH);
	}
}
