<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Bot;

final class TokenGenerator
{
	private const BOT_TOKEN_BYTES = 20;
	private const APPLICATION_TOKEN_BYTES = 16;

	public function generateBotToken(): string
	{
		return bin2hex(random_bytes(self::BOT_TOKEN_BYTES));
	}

	public function generateApplicationToken(): string
	{
		return bin2hex(random_bytes(self::APPLICATION_TOKEN_BYTES));
	}
}
