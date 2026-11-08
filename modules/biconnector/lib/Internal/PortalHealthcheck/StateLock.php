<?php

namespace Bitrix\BIConnector\Internal\PortalHealthcheck;

use Bitrix\Main\Application;

class StateLock
{
	private const NAME = 'biconnector_portal_healthcheck';
	private const TIMEOUT_SECONDS = 10;

	public function lock(): bool
	{
		return Application::getConnection()->lock(self::NAME, self::TIMEOUT_SECONDS);
	}

	public function unlock(): void
	{
		Application::getConnection()->unlock(self::NAME);
	}
}
