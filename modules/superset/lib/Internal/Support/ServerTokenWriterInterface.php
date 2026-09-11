<?php

namespace Bitrix\Superset\Internal\Support;

interface ServerTokenWriterInterface
{
	public function write(?string $token, ?string $refreshToken): void;
}
