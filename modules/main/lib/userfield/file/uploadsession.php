<?php
namespace Bitrix\Main\UserField\File;

use Bitrix\Main\Data\Storage\DeferredStorageDecorator;
use Bitrix\Main\UuidGenerator;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;

class UploadSession
{
	const SESSION_TTL = 60*60*24*3;

	private DeferredStorageDecorator $storage;
	private ?string $sessionId = null;
	private array $context = [];
	private array $deletedFileIds = [];

	private static array $instances = [];

	public static function getInstance(?string $sessionId = null): ?self
	{
		if (isset(self::$instances[$sessionId ?? '']))
		{
			return self::$instances[$sessionId ?? ''];
		}

		$instance = new self();
		self::$instances[$sessionId ?? '']  = $instance;

		if (!$sessionId)
		{
			$sessionId = UuidGenerator::generateV4();
			self::$instances[$sessionId] = $instance;
		}
		$instance->setSessionId($sessionId);

		return $instance;
	}

	public static function loadBySessionId(string $sessionId): ?self
	{
		if (!isset(self::$instances[$sessionId]))
		{
			$instance = new self();
			if ($instance->tryLoadBySessionId($sessionId))
			{
				self::$instances[$sessionId] = $instance;
			}
		}

		return self::$instances[$sessionId] ?? null;
	}

	public static function getBySessionIdBypassingCache(string $sessionId): self
	{
		$instance = new self();
		$instance->storage->reset($instance->getStorageKey($sessionId)); // to bypass in-memory cache
		if (!$instance->tryLoadBySessionId($sessionId))
		{
			$instance->setSessionId($sessionId);
		}

		return $instance;
	}

	public function getSessionId(): string
	{
		return $this->sessionId;
	}

	public function registerFile(int $fileId, array $fileContext): self
	{
		$this->context[$fileId] = $fileContext;
		$this->saveContext();

		return $this;
	}

	public function unregisterFile(int $fileId): self
	{
		unset($this->context[$fileId]);
		if (empty($this->context))
		{
			$this->storage->delete($this->getStorageKey($this->sessionId));
			$this->storage->save();
		}
		else
		{
			$this->saveContext();
		}

		return $this;
	}

	public function hasRegisteredFile(int $getId): bool
	{
		return isset($this->context[$getId]);
	}

	public function getFileContext(int $fileId): ?array
	{
		return $this->context[$fileId] ?? null;
	}

	public function save(): void
	{
		$this->storage->save();
	}

	public function markFileAsDeleted(int $deletedFileId): void
	{
		$this->deletedFileIds[] = $deletedFileId;
		$this->unregisterFile($deletedFileId);
	}

	public function wasFileDeleted(int $fileId): bool
	{
		return in_array($fileId, $this->deletedFileIds, true);
	}

	private function __construct()
	{
		$this->storage = new DeferredStorageDecorator(ServiceLocator::getInstance()->get(PersistentStorageInterface::class));
	}

	private function setSessionId(string $sessionId): void
	{
		$this->sessionId = $sessionId;
	}

	private function getStorageKey(string $sessionId): string
	{
		return 'main.upload_session.' . $sessionId;
	}

	private function tryLoadBySessionId(string $sessionId): bool
	{
		$storedSession = $this->storage->get($this->getStorageKey($sessionId));
		if (!$storedSession)
		{
			return false;
		}

		$this->setSessionId($sessionId);
		$this->setContext($storedSession);

		return true;
	}

	private function setContext(mixed $context): void
	{
		$this->context = $context;
	}

	public function saveContext(): void
	{
		$this->storage->set($this->getStorageKey($this->sessionId), $this->context, self::SESSION_TTL);
	}
}
