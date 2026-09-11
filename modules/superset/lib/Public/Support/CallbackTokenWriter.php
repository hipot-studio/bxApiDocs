<?php

namespace Bitrix\Superset\Public\Support;

use Closure;

final class CallbackTokenWriter implements TokenWriterInterface
{
	public function __construct(private readonly Closure $tokenWriter)
	{
	}

	public function write(?string $token, ?string $refreshToken): void
	{
		($this->tokenWriter)($token, $refreshToken);
	}
}
