<?php

namespace Bitrix\Crm\Integration\DocumentGenerator;

use Bitrix\Main\Data\Storage\Exception\StorageException;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;

final class CreationOperationContext
{
	private const STORAGE_KEY_PREFIX = 'crm.document.create.';
	private const STORAGE_TTL = 3600;

	private static ?self $current = null;

	private ?int $documentId = null;
	private bool $documentIdPersisted = false;

	public function __construct(
		private readonly string $operationId,
		private readonly PersistentStorageInterface $storage,
		private array $state,
	)
	{
		$documentId = (int)($state['documentId'] ?? 0);
		if ($documentId > 0)
		{
			$this->documentId = $documentId;
			$this->documentIdPersisted = true;
		}
	}

	public static function getCurrent(): ?self
	{
		return self::$current;
	}

	public function run(callable $callback): mixed
	{
		$previous = self::$current;
		self::$current = $this;

		try
		{
			return $callback();
		}
		finally
		{
			self::$current = $previous;
		}
	}

	public function rememberDocumentId(int $documentId): void
	{
		if ($documentId <= 0)
		{
			return;
		}

		if ($this->documentId !== $documentId)
		{
			$this->documentIdPersisted = false;
		}

		$this->documentId = $documentId;
		$this->state['documentId'] = $documentId;
	}

	public function persistDocumentId(): void
	{
		if ($this->documentId === null)
		{
			return;
		}

		$this->persistState();
		$this->documentIdPersisted = true;
	}

	public function persistState(): void
	{
		if (!$this->storage->set($this->getStorageKey(), $this->state, self::STORAGE_TTL))
		{
			throw new StorageException('Could not save document creation operation state');
		}
	}

	public function setStatus(string $status): void
	{
		$this->state['status'] = $status;
	}

	public function setNumber(string $number): void
	{
		$this->state['number'] = $number;
	}

	public function getState(): array
	{
		return $this->state;
	}

	public function getDocumentId(): ?int
	{
		return $this->documentId;
	}

	public function isDocumentIdPersisted(): bool
	{
		return $this->documentIdPersisted;
	}

	private function getStorageKey(): string
	{
		return self::STORAGE_KEY_PREFIX . $this->operationId;
	}
}
