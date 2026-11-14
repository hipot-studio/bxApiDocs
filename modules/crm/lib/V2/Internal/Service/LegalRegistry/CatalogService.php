<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\LegalRegistry;

use Bitrix\Crm\V2\Internal\Access\LegalRegistry\RegistryAccess;
use Bitrix\Crm\V2\Internal\Repository\LegalRegistry\CatalogRepository;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\CatalogMetadata;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\CatalogVersion;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\DraftSettings;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\RegistryResult;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use InvalidArgumentException;

class CatalogService
{
	public static function getInstance(): self
	{
		$locator = ServiceLocator::getInstance();
		$service = $locator->has(self::class) ? $locator->get(self::class) : null;

		return $service instanceof self ? $service : new self();
	}

	public function execute(int $userId, string $operation, ?int $documentId, ?CatalogVersion $version,
		?string $name, ?int $expectedRevision, ?CatalogMetadata $metadata = null,
		?DraftSettings $draftSettings = null): RegistryResult
	{
		try
		{
			if ($userId <= 0 || !$this->access($userId)->canEditForm())
			{
				return self::error('NOT_FOUND_OR_DENIED');
			}
			if (!in_array($operation, ['createDocument', 'createDraft', 'updateDraft', 'publish', 'archive',
				'createDocumentWithDraft', 'updateMetadata', 'archiveVersion', 'saveCard',
				'publishCard', 'archiveVersionCard'], true))
			{
				return self::error('INVALID_PAYLOAD');
			}
			if ($operation === 'createDocumentWithDraft')
			{
				if ($name === null || trim($name) === '') { return self::fieldError('name', 'REQUIRED'); }
				if (mb_strlen($name) > 255) { return self::fieldError('name', 'INVALID_VALUE'); }
				if ($documentId !== null || $expectedRevision !== null || $version === null
					|| $version->id !== null || $version->documentId !== null || $version->number !== null
					|| $version->revision !== null || $version->state !== 'draft'
					|| $version->publishedAt !== null || $version->publishedBy !== null)
				{
					return self::error('INVALID_PAYLOAD');
				}
				json_encode([$name, $metadata, $draftSettings], JSON_THROW_ON_ERROR);
				(new VersionRenderer())->validateContent($version);

				return $this->createDocumentWithDraft($userId, $name, $version, $metadata, $draftSettings);
			}
			if ($operation === 'saveCard')
			{
				if ($documentId === null || $documentId <= 0 || $documentId > 2147483647
					|| $expectedRevision === null || $expectedRevision <= 0
					|| $expectedRevision > 9007199254740991 || $version === null
					|| ($version->documentId !== null && $version->documentId !== $documentId)
					|| $version->state !== 'draft' || $version->publishedAt !== null
					|| $version->publishedBy !== null)
				{
					return self::error('INVALID_PAYLOAD');
				}
				if ($name === null || trim($name) === '') { return self::fieldError('name', 'REQUIRED'); }
				if (mb_strlen($name) > 255) { return self::fieldError('name', 'INVALID_VALUE'); }
				if ($version->id === null && ($version->number !== null || $version->revision !== null))
				{
					return self::error('INVALID_PAYLOAD');
				}
			$version = self::withDocument($version, $documentId, $draftSettings);
			json_encode([$name, $metadata, $draftSettings], JSON_THROW_ON_ERROR);

			return $this->saveCard($documentId, $version, $name, $metadata, $expectedRevision);
			}
			if (in_array($operation, ['publishCard', 'archiveVersionCard'], true))
			{
				if ($documentId === null || $documentId <= 0 || $documentId > 2147483647
					|| $version?->id === null || ($version->documentId !== null
						&& $version->documentId !== $documentId)
					|| $name !== null || $metadata !== null || $draftSettings !== null
					|| $expectedRevision === null || $expectedRevision <= 0
					|| $expectedRevision > 9007199254740991)
				{
					return self::error('INVALID_PAYLOAD');
				}
				$pool = \Bitrix\Main\Application::getInstance()->getConnectionPool();
				$pool->useMasterOnly(true);
				try { $stored = $this->readVersion($documentId, $version->id); }
				finally { $pool->useMasterOnly(false); }
				if ($stored === null) { return self::error('NOT_FOUND_OR_DENIED'); }
				if ($operation === 'archiveVersionCard')
				{
					return $this->archiveVersionCard($documentId, $version->id,
						$stored->revision, $expectedRevision);
				}
				$frozen = null;
				if ($stored->state === 'draft')
				{
					(new VersionRenderer())->validateContent($stored);
					if ($stored->draftSettings?->type === 'S')
					{
						$frozen = MainAgreementSnapshotGateway::getInstance()->captureDraft($stored);
					}
				}

				return $this->publishCard($documentId, $version->id, $stored->revision,
					$expectedRevision, $userId, $frozen);
			}
			if ($operation === 'updateMetadata')
			{
				if ($documentId === null || $documentId <= 0 || $documentId > 2147483647
					|| $version !== null || $draftSettings !== null || $expectedRevision === null
					|| $expectedRevision <= 0 || $expectedRevision > 2147483647
					|| ($name === null && $metadata === null))
				{
					return self::error('INVALID_PAYLOAD');
				}
				if ($name !== null && trim($name) === '') { return self::fieldError('name', 'REQUIRED'); }
				if ($name !== null && mb_strlen($name) > 255) { return self::fieldError('name', 'INVALID_VALUE'); }
				json_encode([$name, $metadata], JSON_THROW_ON_ERROR);

				return $this->updateMetadata($documentId, $name, $metadata, $expectedRevision);
			}
			if ($operation === 'archiveVersion')
			{
				if ($documentId === null || $documentId <= 0 || $documentId > 2147483647
					|| $version?->id === null || ($version->documentId !== null && $version->documentId !== $documentId)
					|| $name !== null || $metadata !== null || $draftSettings !== null
					|| $expectedRevision === null || $expectedRevision <= 0 || $expectedRevision > 2147483647)
				{
					return self::error('INVALID_PAYLOAD');
				}

				return $this->archiveVersion($documentId, $version->id, $expectedRevision);
			}
			if ($operation === 'createDocument')
			{
				if ($documentId !== null || $version !== null || $expectedRevision !== null
					|| $name === null || trim($name) === '' || mb_strlen($name) > 255 || $draftSettings !== null)
				{
					return self::error('INVALID_PAYLOAD');
				}
				json_encode($name, JSON_THROW_ON_ERROR);

				return $metadata === null ? $this->create($name, $userId)
					: $this->createWithMetadata($name, $userId, $metadata);
			}
			if ($documentId === null || $documentId <= 0 || $documentId > 2147483647 || $name !== null)
			{
				return self::error('INVALID_PAYLOAD');
			}
			if ($operation === 'archive')
			{
				return $version !== null || $expectedRevision !== null || $metadata !== null || $draftSettings !== null
					? self::error('INVALID_PAYLOAD') : $this->archive($documentId);
			}
			if ($version === null || $metadata !== null
				|| ($version->documentId !== null && $version->documentId !== $documentId))
			{
				return self::error('INVALID_PAYLOAD');
			}
			if ($operation === 'createDraft')
			{
				if ($version->id !== null || $version->number !== null || $version->revision !== null
					|| $expectedRevision !== null || $version->state !== 'draft'
					|| $version->publishedAt !== null || $version->publishedBy !== null)
				{
					return self::error('INVALID_PAYLOAD');
				}
				$version = self::withDocument($version, $documentId, $draftSettings);
				(new VersionRenderer())->validateContent($version);

				return $this->save($version, null);
			}
			if ($version->id === null || $expectedRevision === null
				|| $expectedRevision <= 0 || $expectedRevision > 2147483647)
			{
				return self::error('INVALID_PAYLOAD');
			}
			if ($operation === 'updateDraft')
			{
				if ($version->state !== 'draft') { return self::error('VERSION_IMMUTABLE'); }
				$version = self::withDocument($version, $documentId, $draftSettings);
				(new VersionRenderer())->validateContent($version);

				return $this->save($version, $expectedRevision);
			}
			if ($draftSettings !== null) { return self::error('INVALID_PAYLOAD'); }
			$stored = $this->readVersion($documentId, $version->id);
			if ($stored === null) { return self::error('NOT_FOUND_OR_DENIED'); }
			(new VersionRenderer())->validateContent($stored);
			if ($stored->draftSettings?->type === 'S')
			{
				$frozen = MainAgreementSnapshotGateway::getInstance()->captureDraft($stored);

				return $this->publishFrozen($frozen, $expectedRevision, $userId);
			}

			return $this->publish($stored->id, $expectedRevision, $userId);
		}
		catch (InvalidArgumentException|\JsonException) { return self::error('INVALID_PAYLOAD'); }
		catch (\Throwable) { return self::error('STORAGE_ERROR'); }
	}

	private function createDocumentWithDraft(int $userId, string $name, CatalogVersion $version,
		?CatalogMetadata $metadata, ?DraftSettings $draftSettings): RegistryResult
	{
		return $this->transaction(function () use ($userId, $name, $version, $metadata, $draftSettings): RegistryResult {
			$created = $metadata === null ? $this->create($name, $userId)
				: $this->createWithMetadata($name, $userId, $metadata);
			if (!$created->isSuccess()) { return $created; }
			$documentId = $created->getDocument()?->id;
			if ($documentId === null) { return self::error('STORAGE_ERROR'); }
			$saved = $this->save(self::withDocument($version, $documentId, $draftSettings), null);
			if (!$saved->isSuccess()) { return $saved; }
			$document = (new CatalogRepository())->getDocument($documentId);
			if (!$document->isSuccess() || $document->getDocument() === null)
			{
				return self::error('STORAGE_ERROR');
			}

			return $document->setVersions($saved->getVersions())->setRevision($document->getDocument()->revision);
		});
	}

	public function importCurrent(int $userId, int $agreementId): RegistryResult
	{
		try
		{
			if ($userId <= 0 || !$this->access($userId)->canEditForm()) { return self::error('NOT_FOUND_OR_DENIED'); }
			$snapshot = $this->captureAgreement($agreementId);
			$name = $snapshot['name']; $version = $snapshot['version'];
			if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 255
				|| !$version instanceof CatalogVersion || $version->id !== null || $version->documentId !== null
				|| $version->number !== null || $version->revision !== null || $version->state !== 'draft'
				|| $version->publishedAt !== null || $version->publishedBy !== null)
			{
				return self::error('INVALID_PAYLOAD');
			}
			json_encode($name, JSON_THROW_ON_ERROR);
			(new VersionRenderer())->validateContent($version);

			return $this->transaction(function () use ($name, $userId, $agreementId, $version): RegistryResult {
				$created = $this->create($name, $userId, 'main', $agreementId);
				if (!$created->isSuccess()) { return $created; }
				$document = $created->getDocument();
				if ($document?->id === null) { return self::error('STORAGE_ERROR'); }
				$saved = $this->save(self::withDocument($version, $document->id), null);
				if (!$saved->isSuccess()) { return $saved; }
				$versions = $saved->getVersions();
				if (count($versions) !== 1) { return self::error('STORAGE_ERROR'); }

				return (new RegistryResult())->setVersions($versions)->setDocument(
					new \Bitrix\Crm\V2\Public\Entity\LegalRegistry\CatalogDocument($document->id, $document->name,
						$document->archived, $document->originModule, $document->originId,
						$document->createdAt, $document->createdBy, $versions, $document->metadata, $document->revision),
				);
			});
		}
		catch (\UnexpectedValueException) { return self::error('NOT_FOUND_OR_DENIED'); }
		catch (InvalidArgumentException|\JsonException) { return self::error('INVALID_PAYLOAD'); }
		catch (\Throwable) { return self::error('STORAGE_ERROR'); }
	}

	protected function captureAgreement(int $id): array
	{
		return MainAgreementSnapshotGateway::getInstance()->captureCurrent($id);
	}

	protected function transaction(callable $callback): RegistryResult
	{
		$pool = \Bitrix\Main\Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		$connection = \Bitrix\Main\Application::getConnection(); $started = false;
		try
		{
			$connection->startTransaction(); $started = true; $result = $callback();
			$started = false;
			if ($result->isSuccess()) { $connection->commitTransaction(); }
			else { $this->rollback(); }

			return $result;
		}
		catch (\Throwable)
		{
			if ($started)
			{
				try { $this->rollback(); }
				catch (\Throwable) { /* Only safe storage failure escapes. */ }
			}

			return self::error('STORAGE_ERROR');
		}
		finally { $pool->useMasterOnly(false); }
	}

	private function rollback(): void
	{
		try { \Bitrix\Main\Application::getConnection()->rollbackTransaction(); }
		catch (\Bitrix\Main\DB\TransactionException $exception)
		{
			// Native MySQL executes ROLLBACK TO SAVEPOINT and restores the level before this exact signal.
			if ($exception->getMessage() !== 'Nested rollbacks are unsupported.') { throw $exception; }
		}
	}

	protected function access(int $userId): RegistryAccess { return RegistryAccess::forUser($userId); }

	protected function create(string $name, int $userId, ?string $module = null, ?int $origin = null): RegistryResult
	{
		return (new CatalogRepository())->createDocument($name, $userId, $module, $origin);
	}

	protected function createWithMetadata(string $name, int $userId, CatalogMetadata $metadata): RegistryResult
	{
		return (new CatalogRepository())->createDocument($name, $userId, null, null, $metadata);
	}

	protected function save(CatalogVersion $version, ?int $revision): RegistryResult
	{
		return (new CatalogRepository())->saveDraft($version, $revision);
	}

	protected function publish(int $id, int $revision, int $userId): RegistryResult
	{
		return (new CatalogRepository())->publish($id, $revision, $userId);
	}

	protected function publishFrozen(CatalogVersion $frozen, int $revision, int $userId): RegistryResult
	{
		return (new CatalogRepository())->publish($frozen->id, $revision, $userId, $frozen);
	}

	protected function archive(int $documentId): RegistryResult
	{
		return (new CatalogRepository())->archive($documentId);
	}

	protected function updateMetadata(int $documentId, ?string $name, ?CatalogMetadata $metadata,
		int $expectedRevision): RegistryResult
	{
		return (new CatalogRepository())->updateMetadata($documentId, $name, $metadata, $expectedRevision);
	}

	protected function saveCard(int $documentId, CatalogVersion $version, string $name,
		?CatalogMetadata $metadata, int $expectedCardRevision): RegistryResult
	{
		return (new CatalogRepository())->saveCard($documentId, $version, $name, $metadata, $expectedCardRevision);
	}

	protected function publishCard(int $documentId, int $versionId, int $versionRevision,
		int $cardRevision, int $userId, ?CatalogVersion $frozen): RegistryResult
	{
		return (new CatalogRepository())->publish($versionId, $versionRevision, $userId, $frozen,
			$cardRevision, $documentId);
	}

	protected function archiveVersionCard(int $documentId, int $versionId, int $versionRevision,
		int $cardRevision): RegistryResult
	{
		return (new CatalogRepository())->archiveVersion($documentId, $versionId, $versionRevision, $cardRevision);
	}

	protected function archiveVersion(int $documentId, int $versionId, int $expectedRevision): RegistryResult
	{
		return (new CatalogRepository())->archiveVersion($documentId, $versionId, $expectedRevision);
	}

	protected function readVersion(int $documentId, int $versionId): ?CatalogVersion
	{
		$result = (new CatalogRepository())->getVersion($documentId, $versionId);
		if (!$result->isSuccess())
		{
			if ($result->getErrors()[0]->getCode() === 'STORAGE_ERROR')
			{
				throw new \RuntimeException('STORAGE_ERROR');
			}

			return null;
		}

		return $result->getVersions()[0] ?? null;
	}

	private static function withDocument(CatalogVersion $v, int $documentId,
		?DraftSettings $draftSettings = null): CatalogVersion
	{
		return new CatalogVersion($v->id, $documentId, $v->number, $v->revision, $v->state, $v->languageId,
			$v->template, $v->label, $v->substitutionSchema, $v->contentHash, $v->expiryRule,
			$v->validFrom, $v->validTo, $v->displayUrl, $v->publishedAt, $v->publishedBy,
			$draftSettings ?? $v->draftSettings);
	}

	private static function fieldError(string $field, string $code): RegistryResult
	{
		return (new RegistryResult())->setFieldErrors([$field => $code])
			->addError(new Error('Invalid catalog field.', 'INVALID_PAYLOAD'));
	}

	protected static function error(string $code): RegistryResult
	{
		return (new RegistryResult())->addError(new Error($code, $code));
	}
}
