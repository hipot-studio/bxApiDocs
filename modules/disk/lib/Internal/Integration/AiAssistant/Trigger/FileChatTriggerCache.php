<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Integration\AiAssistant\Trigger;

use Bitrix\Main\Application;
use Bitrix\Main\Data\ManagedCache;

/**
 * What the file menu keeps between requests about the disk.file-chat trigger: that its registration was
 * found ready, and which users it was found available for. Only positive answers are kept, so a trigger
 * that comes into service is seen at once, and switching it off drops everything. The launch of the
 * trigger never reads any of it.
 */
class FileChatTriggerCache
{
	private const TABLE_ID = 'disk_file_chat_trigger';
	private const REGISTRATION_KEY = 'disk_file_chat_trigger_ready';
	private const REGISTRATION_TTL = 3600;
	private const AVAILABILITY_KEY_PREFIX = 'disk_file_chat_trigger_user_';
	private const AVAILABILITY_TTL = 600;

	private readonly ManagedCache $managedCache;

	public function __construct(?ManagedCache $managedCache = null)
	{
		$this->managedCache = $managedCache ?? Application::getInstance()->getManagedCache();
	}

	public function isRegistrationReady(): bool
	{
		return $this->has(self::REGISTRATION_KEY, self::REGISTRATION_TTL);
	}

	public function rememberRegistrationReady(): void
	{
		if (!$this->isRegistrationReady())
		{
			$this->keep(self::REGISTRATION_KEY, self::REGISTRATION_TTL);
		}
	}

	public function forgetRegistration(): void
	{
		if ($this->isRegistrationReady())
		{
			$this->managedCache->clean(self::REGISTRATION_KEY, self::TABLE_ID);
		}
	}

	public function isAvailableForUser(int $userId): bool
	{
		return $this->has(self::AVAILABILITY_KEY_PREFIX . $userId, self::AVAILABILITY_TTL);
	}

	public function rememberAvailableForUser(int $userId): void
	{
		$this->keep(self::AVAILABILITY_KEY_PREFIX . $userId, self::AVAILABILITY_TTL);
	}

	/** Drops the registration and every user at once; a write that raced with it is discarded. */
	public function clear(): void
	{
		$this->managedCache->cleanDir(self::TABLE_ID);
	}

	private function has(string $key, int $ttl): bool
	{
		return $this->managedCache->read($ttl, $key, self::TABLE_ID) && $this->managedCache->get($key) === true;
	}

	private function keep(string $key, int $ttl): void
	{
		// The managed cache writes only an entry this request has read.
		$this->managedCache->read($ttl, $key, self::TABLE_ID);
		$this->managedCache->setImmediate($key, true);
	}
}
