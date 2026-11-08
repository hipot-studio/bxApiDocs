<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Controller;

interface IncomingServerAware
{
	public function setIncomingServerIss(?string $iss): void;

	public function setIncomingOrigin(?string $origin): void;
}
