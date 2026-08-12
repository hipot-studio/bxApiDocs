<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Controller;

interface IncomingServerAware
{
	public function setIncomingServerIss(?string $iss): void;
}
