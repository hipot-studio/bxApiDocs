<?php

namespace Bitrix\AI\Tokenizer;

interface TokenizerInterface
{
	public function count(string $text): int;
}
