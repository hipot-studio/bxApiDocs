<?php

namespace Bitrix\Crm\Integration\DocumentGenerator;

use Bitrix\DocumentGenerator\CreationMethod;
use Bitrix\DocumentGenerator\Document;
use Bitrix\Main\Application;
use Bitrix\Main\Data\Storage\Exception\StorageException;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\Exception as DbException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Random;
use Closure;

final class CreationOperationService
{
	public const ERROR_BUSY = 'DOCUMENT_CREATION_BUSY';
	public const ERROR_FAILED = 'DOCUMENT_CREATION_FAILED';
	public const ERROR_STORAGE_UNAVAILABLE = 'DOCUMENT_CREATION_STORAGE_UNAVAILABLE';
	public const ERROR_RECONCILIATION_REQUIRED = 'DOCUMENT_CREATION_RECONCILIATION_REQUIRED';

	public const BINDING_BOUND = 'bound';
	public const BINDING_NOT_BOUND = 'notBound';
	public const BINDING_UNAVAILABLE = 'unavailable';

	public const STATE_RUNNING = 'running';
	public const STATE_FAILED = 'failed';
	public const STATE_DOCX_READY = 'docxReady';
	public const STATE_DOCX_READY_UNTRACKED = 'docxReadyUntracked';
	public const STATE_RECONCILIATION_REQUIRED = 'reconciliationRequired';

	private const STORAGE_KEY_PREFIX = 'crm.document.create.';
	private const STORAGE_TTL = 3600;
	private const LOCK_TIMEOUT = 2;
	private const RUNNING_LEASE = 300;
	private const RETRY_AFTER = 2;

	private readonly PersistentStorageInterface $storage;
	private readonly Connection $connection;
	private readonly Closure $clock;

	public function __construct(
		?PersistentStorageInterface $storage = null,
		?Connection $connection = null,
		?callable $clock = null,
	)
	{
		$this->storage = $storage ?? ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
		$this->connection = $connection ?? Application::getConnection();
		$this->clock = $clock !== null ? Closure::fromCallable($clock) : static fn(): int => time();
	}

	public function generateOperationId(): string
	{
		return Random::getString(32, true);
	}

	public function isOperationBoundToContext(
		string $operationId,
		int $userId,
		string $contextHash,
	): bool
	{
		return $this->resolveOperationBinding($operationId, $userId, $contextHash) === self::BINDING_BOUND;
	}

	public function resolveOperationBinding(
		string $operationId,
		int $userId,
		string $contextHash,
	): string
	{
		if (!$this->isValidOperationId($operationId))
		{
			return self::BINDING_NOT_BOUND;
		}

		try
		{
			$state = $this->storage->get($this->getStorageKey($operationId));
		}
		catch (StorageException | DbException)
		{
			return self::BINDING_UNAVAILABLE;
		}

		return $this->isBoundState($state, $userId, $contextHash)
			? self::BINDING_BOUND
			: self::BINDING_NOT_BOUND
		;
	}

	public function run(
		string $operationId,
		int $userId,
		string $contextHash,
		callable $documentFactory,
		?callable $documentLoader = null,
	): Result
	{
		if (!$this->isValidOperationId($operationId))
		{
			return $this->createErrorResult(
				self::ERROR_FAILED,
				$this->createOperationData($operationId, self::STATE_FAILED),
			);
		}

		try
		{
			$state = $this->storage->get($this->getStorageKey($operationId));
		}
		catch (StorageException | DbException)
		{
			return $this->createStorageUnavailableResult($operationId);
		}

		if ($state !== null)
		{
			$result = $this->handleExistingState(
				$operationId,
				$userId,
				$contextHash,
				$state,
				$documentLoader,
			);
			if ($result !== null)
			{
				return $result;
			}
		}

		return $this->createDocument(
			$operationId,
			$userId,
			$contextHash,
			is_array($state) ? $state : null,
			$documentFactory,
			$documentLoader,
		);
	}

	private function handleExistingState(
		string $operationId,
		int $userId,
		string $contextHash,
		mixed $state,
		?callable $documentLoader,
	): ?Result
	{
		if (!$this->isBoundState($state, $userId, $contextHash))
		{
			return $this->createErrorResult(
				self::ERROR_FAILED,
				$this->createOperationData($operationId, self::STATE_FAILED),
			);
		}

		$documentId = (int)($state['documentId'] ?? 0);
		if ($documentId > 0)
		{
			return $this->loadCreatedDocument($operationId, $state, $documentId, $documentLoader);
		}

		if (($state['status'] ?? null) !== self::STATE_RUNNING)
		{
			return null;
		}

		$startedAt = (int)($state['startedAt'] ?? 0);
		if ($startedAt > 0 && ($this->getNow() - $startedAt) <= self::RUNNING_LEASE)
		{
			return $this->createErrorResult(
				self::ERROR_BUSY,
				$this->createOperationData(
					$operationId,
					self::STATE_RUNNING,
					null,
					true,
					self::RETRY_AFTER,
				),
			);
		}

		return $this->createErrorResult(
			self::ERROR_RECONCILIATION_REQUIRED,
			$this->createOperationData($operationId, self::STATE_RECONCILIATION_REQUIRED),
		);
	}

	private function createDocument(
		string $operationId,
		int $userId,
		string $contextHash,
		?array $knownState,
		callable $documentFactory,
		?callable $documentLoader,
	): Result
	{
		$storageKey = $this->getStorageKey($operationId);
		try
		{
			$isLocked = $this->connection->lock($storageKey, self::LOCK_TIMEOUT);
		}
		catch (DbException)
		{
			return $this->createStorageUnavailableResult($operationId);
		}

		if (!$isLocked)
		{
			return $this->createErrorResult(
				self::ERROR_BUSY,
				$this->createOperationData(
					$operationId,
					self::STATE_RUNNING,
					null,
					true,
					self::RETRY_AFTER,
				),
			);
		}

		try
		{
			try
			{
				$state = $this->storage->get($storageKey);
			}
			catch (StorageException | DbException)
			{
				return $this->createStorageUnavailableResult($operationId);
			}

			if ($state !== null && $state !== $knownState)
			{
				$result = $this->handleExistingState(
					$operationId,
					$userId,
					$contextHash,
					$state,
					$documentLoader,
				);
				if ($result !== null)
				{
					return $result;
				}
			}

			if ($state !== null && !$this->isBoundState($state, $userId, $contextHash))
			{
				return $this->createErrorResult(
					self::ERROR_FAILED,
					$this->createOperationData($operationId, self::STATE_FAILED),
				);
			}

			$state = [
				'userId' => $userId,
				'contextHash' => $contextHash,
				'status' => self::STATE_RUNNING,
				'documentId' => null,
				'number' => is_array($state) ? ($state['number'] ?? null) : null,
				'startedAt' => $this->getNow(),
			];

			try
			{
				$this->persistState($storageKey, $state);
			}
			catch (StorageException | DbException)
			{
				return $this->createStorageUnavailableResult($operationId);
			}

			$number = is_string($state['number']) ? $state['number'] : '';
			$factoryData = $number !== '' ? ['NUMBER' => $number] : [];
			$document = $documentFactory($factoryData);
			if (!$document instanceof Document)
			{
				$this->persistFailedState($storageKey, $state);

				return $this->createErrorResult(
					self::ERROR_FAILED,
					$this->createOperationData(
						$operationId,
						self::STATE_FAILED,
						null,
						true,
						self::RETRY_AFTER,
					),
				);
			}

			$context = new CreationOperationContext($operationId, $this->storage, $state);
			if ($number === '')
			{
				$number = $document->getNumber(false);
				$context->setNumber($number);
				try
				{
					$context->persistState();
				}
				catch (StorageException | DbException)
				{
					return $this->createStorageUnavailableResult($operationId);
				}
			}

			CreationMethod::markDocumentAsCreatedByPublic($document);
			$fileResult = $context->run(
				static fn(): Result => $document->getFile(empty($document->PDF_ID), true),
			);

			$documentId = (int)($document->ID ?? ($fileResult->getData()['id'] ?? 0));
			$context->rememberDocumentId($documentId);

			return $this->finishCreation($operationId, $document, $fileResult, $context);
		}
		finally
		{
			try
			{
				$this->connection->unlock($storageKey);
			}
			catch (DbException)
			{
			}
		}
	}

	private function finishCreation(
		string $operationId,
		Document $document,
		Result $fileResult,
		CreationOperationContext $context,
	): Result
	{
		$documentId = $context->getDocumentId();
		$isDocxReady = $fileResult->isSuccess() || $this->hasReadyDocxData($fileResult, $documentId);
		$targetStatus = $isDocxReady ? self::STATE_DOCX_READY : self::STATE_FAILED;
		$context->setStatus($targetStatus);
		$wasDocumentIdPersisted = $context->isDocumentIdPersisted();

		if ($documentId !== null && !$wasDocumentIdPersisted)
		{
			try
			{
				$context->persistDocumentId();
			}
			catch (StorageException | DbException)
			{
				if ($isDocxReady)
				{
					return $this->createSuccessResult(
						$operationId,
						self::STATE_DOCX_READY_UNTRACKED,
						$documentId,
						$document,
						$fileResult,
					);
				}

				return $this->createErrorResult(
					self::ERROR_FAILED,
					$this->createOperationData($operationId, self::STATE_FAILED, $documentId),
				);
			}
		}
		elseif ($documentId !== null)
		{
			try
			{
				$context->persistState();
			}
			catch (StorageException | DbException)
			{
			}
		}
		elseif (!$isDocxReady)
		{
			try
			{
				$context->persistState();
			}
			catch (StorageException | DbException)
			{
				return $this->createStorageUnavailableResult($operationId);
			}
		}

		if (!$isDocxReady)
		{
			return $this->createErrorResult(
				self::ERROR_FAILED,
				$this->createOperationData(
					$operationId,
					self::STATE_FAILED,
					$documentId,
					true,
					$documentId !== null ? self::RETRY_AFTER : null,
				),
			);
		}

		return $this->createSuccessResult(
			$operationId,
			self::STATE_DOCX_READY,
			$documentId,
			$document,
			$fileResult,
		);
	}

	private function hasReadyDocxData(Result $fileResult, ?int $documentId): bool
	{
		$data = $fileResult->getData();
		$downloadUrl = $data['downloadUrl'] ?? null;

		return $documentId !== null
			&& (int)($data['id'] ?? 0) === $documentId
			&& is_string($downloadUrl)
			&& $downloadUrl !== ''
		;
	}

	private function loadCreatedDocument(
		string $operationId,
		array $state,
		int $documentId,
		?callable $documentLoader,
	): Result
	{
		$document = $documentLoader !== null
			? $documentLoader($documentId)
			: Document::loadById($documentId)
		;
		if (!$document instanceof Document)
		{
			return $this->createErrorResult(
				self::ERROR_FAILED,
				$this->createOperationData($operationId, self::STATE_FAILED, $documentId),
			);
		}

		$fileResult = $document->getFile(false, true);
		if (!$fileResult->isSuccess())
		{
			return $this->createErrorResult(
				self::ERROR_FAILED,
				$this->createOperationData(
					$operationId,
					(string)($state['status'] ?? self::STATE_FAILED),
					$documentId,
					true,
					self::RETRY_AFTER,
				),
			);
		}

		return $this->createSuccessResult(
			$operationId,
			self::STATE_DOCX_READY,
			$documentId,
			$document,
			$fileResult,
		);
	}

	private function persistState(string $storageKey, array $state): void
	{
		if (!$this->storage->set($storageKey, $state, self::STORAGE_TTL))
		{
			throw new StorageException('Could not save document creation operation state');
		}
	}

	private function persistFailedState(string $storageKey, array $state): void
	{
		$state['status'] = self::STATE_FAILED;
		try
		{
			$this->persistState($storageKey, $state);
		}
		catch (StorageException | DbException)
		{
		}
	}

	private function isBoundState(mixed $state, int $userId, string $contextHash): bool
	{
		return is_array($state)
			&& (int)($state['userId'] ?? 0) === $userId
			&& is_string($state['contextHash'] ?? null)
			&& hash_equals($state['contextHash'], $contextHash)
		;
	}

	private function createSuccessResult(
		string $operationId,
		string $status,
		?int $documentId,
		Document $document,
		Result $fileResult,
	): Result
	{
		return (new Result())->setData([
			'operation' => $this->createOperationData(
				$operationId,
				$status,
				$documentId,
				false,
				null,
				true,
			),
			'document' => $document,
			'documentData' => $fileResult->getData(),
		]);
	}

	private function createStorageUnavailableResult(string $operationId): Result
	{
		return $this->createErrorResult(
			self::ERROR_STORAGE_UNAVAILABLE,
			$this->createOperationData(
				$operationId,
				self::STATE_FAILED,
				null,
				true,
				self::RETRY_AFTER,
			),
		);
	}

	private function createErrorResult(string $code, array $operation): Result
	{
		$messageKey = match ($code) {
			self::ERROR_BUSY => 'CRM_DOCUMENT_CREATION_BUSY',
			self::ERROR_STORAGE_UNAVAILABLE => 'CRM_DOCUMENT_CREATION_STORAGE_UNAVAILABLE',
			self::ERROR_RECONCILIATION_REQUIRED => 'CRM_DOCUMENT_CREATION_RECONCILIATION_REQUIRED',
			default => 'CRM_DOCUMENT_CREATION_FAILED',
		};

		$result = new Result();
		$result->addError(new Error(
			(string)Loc::getMessage($messageKey),
			$code,
			['operation' => $operation],
		));

		return $result;
	}

	private function createOperationData(
		string $operationId,
		string $status,
		?int $documentId = null,
		bool $retryAllowed = false,
		?int $retryAfter = null,
		bool $docxAvailable = false,
	): array
	{
		return [
			'id' => $operationId,
			'state' => $status,
			'documentId' => $documentId,
			'retryAllowed' => $retryAllowed,
			'retryAfter' => $retryAfter,
			'docxAvailable' => $docxAvailable,
		];
	}

	private function getStorageKey(string $operationId): string
	{
		return self::STORAGE_KEY_PREFIX . $operationId;
	}

	private function isValidOperationId(string $operationId): bool
	{
		return preg_match('/^[A-Za-z0-9]{32}$/D', $operationId) === 1;
	}

	private function getNow(): int
	{
		return ($this->clock)();
	}
}
