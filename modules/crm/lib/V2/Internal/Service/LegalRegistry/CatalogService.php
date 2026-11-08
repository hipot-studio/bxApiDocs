<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\LegalRegistry;

use Bitrix\Crm\V2\Internal\Access\LegalRegistry\RegistryAccess;
use Bitrix\Crm\V2\Internal\Repository\LegalRegistry\CatalogRepository;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\CatalogVersion;
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
		?string $name, ?int $expectedRevision): RegistryResult
	{
		try
		{
			if ($userId <= 0 || !$this->access($userId)->canEditForm())
			{
				return self::error('NOT_FOUND_OR_DENIED');
			}
			if (!in_array($operation, ['createDocument', 'createDraft', 'updateDraft', 'publish', 'archive'], true))
			{
				return self::error('INVALID_PAYLOAD');
			}
			if ($operation === 'createDocument')
			{
				if ($documentId !== null || $version !== null || $expectedRevision !== null
					|| $name === null || trim($name) === '' || mb_strlen($name) > 255)
				{
					return self::error('INVALID_PAYLOAD');
				}
				json_encode($name, JSON_THROW_ON_ERROR);

				return $this->create($name, $userId);
			}
			if ($documentId === null || $documentId <= 0 || $documentId > 2147483647 || $name !== null)
			{
				return self::error('INVALID_PAYLOAD');
			}
			if ($operation === 'archive')
			{
				return $version !== null || $expectedRevision !== null
					? self::error('INVALID_PAYLOAD') : $this->archive($documentId);
			}
			if ($version === null || ($version->documentId !== null && $version->documentId !== $documentId))
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
				$version = self::withDocument($version, $documentId);
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
				$version = self::withDocument($version, $documentId);
				(new VersionRenderer())->validateContent($version);

				return $this->save($version, $expectedRevision);
			}
			$stored = $this->readVersion($documentId, $version->id);
			if ($stored === null) { return self::error('NOT_FOUND_OR_DENIED'); }
			(new VersionRenderer())->validateContent($stored);

			return $this->publish($stored->id, $expectedRevision, $userId);
		}
		catch (InvalidArgumentException|\JsonException) { return self::error('INVALID_PAYLOAD'); }
		catch (\Throwable) { return self::error('STORAGE_ERROR'); }
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
						$document->createdAt, $document->createdBy, $versions),
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

	protected function save(CatalogVersion $version, ?int $revision): RegistryResult
	{
		return (new CatalogRepository())->saveDraft($version, $revision);
	}

	protected function publish(int $id, int $revision, int $userId): RegistryResult
	{
		return (new CatalogRepository())->publish($id, $revision, $userId);
	}

	protected function archive(int $documentId): RegistryResult
	{
		return (new CatalogRepository())->archive($documentId);
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

	private static function withDocument(CatalogVersion $v, int $documentId): CatalogVersion
	{
		return new CatalogVersion($v->id, $documentId, $v->number, $v->revision, $v->state, $v->languageId,
			$v->template, $v->label, $v->substitutionSchema, $v->contentHash, $v->expiryRule,
			$v->validFrom, $v->validTo, $v->displayUrl, $v->publishedAt, $v->publishedBy);
	}

	protected static function error(string $code): RegistryResult
	{
		return (new RegistryResult())->addError(new Error($code, $code));
	}
}
