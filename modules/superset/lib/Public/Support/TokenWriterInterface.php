<?php

namespace Bitrix\Superset\Public\Support;

use Bitrix\Superset\Internal\Support\ServerTokenWriterInterface;

interface TokenWriterInterface extends ServerTokenWriterInterface
{
	public function write(?string $token, ?string $refreshToken): void;
}
