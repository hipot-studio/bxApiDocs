<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Controller\VibecodePort;

use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\Controller;
use Bitrix\Vibecodeconnector\Infrastructure\Controller\ActionFilter\CheckIncomingJwt;
use Bitrix\Vibecodeconnector\Infrastructure\Controller\IncomingServerAware;

abstract class Base extends Controller implements IncomingServerAware
{
	protected ?string $incomingServerIss = null;
	protected ?string $incomingOrigin = null;

	public function setIncomingServerIss(?string $iss): void
	{
		$this->incomingServerIss = $iss;
	}

	public function setIncomingOrigin(?string $origin): void
	{
		$this->incomingOrigin = $origin;
	}

	protected function getDefaultPreFilters()
	{
		return [
			new HttpMethod([HttpMethod::METHOD_POST]),
			new CheckIncomingJwt(),
			new Csrf(false),
		];
	}
}
