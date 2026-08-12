<?php

namespace Bitrix\Superset\Internal\Services;

use Bitrix\Main\Application;
use Bitrix\Superset\Internal\Entities\Server;

final class JwtSynchronizationLock
{
	private const LOCK_KEY_PREFIX = 'superset:jwt-sync:';

	public function acquire(Server $server, int $timeout): bool
	{
		return Application::getConnection()->lock($this->buildLockKey($server), $timeout);
	}

	public function release(Server $server): void
	{
		Application::getConnection()->unlock($this->buildLockKey($server));
	}

	private function buildLockKey(Server $server): string
	{
		$host = rtrim((string)$server->getHost(), '/');
		$instanceUsername = (string)$server->getInstanceUsername();

		return self::LOCK_KEY_PREFIX . sha1(strtolower($host) . '|' . strtolower($instanceUsername));
	}
}
