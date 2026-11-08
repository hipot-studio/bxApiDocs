<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\LegalRegistry;

use Bitrix\Crm\V2\Internal\Model\LegalRegistry\DocumentTable;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\VersionTable;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\CatalogDocument;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\CatalogVersion;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\ExpiryRule;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\RegistryResult;
use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;

/** Internal persistence only; callers must apply registry access before invocation. */
final class CatalogRepository
{
	public function createDocument(string $name, int $actorId, ?string $originModule = null, ?int $originId = null): RegistryResult
	{
		if ($actorId <= 0 || ($originModule === null) !== ($originId === null)) { return self::error('INVALID_PAYLOAD'); }

		return $this->transaction(function () use ($name, $actorId, $originModule, $originId): RegistryResult {
			$saved = DocumentTable::add(['NAME' => $name, 'CREATED_AT' => new DateTime(), 'CREATED_BY' => $actorId,
				'ARCHIVED' => 'N', 'ORIGIN_MODULE' => $originModule, 'ORIGIN_ID' => $originId]);
			if (!$saved->isSuccess()) { return self::error('INVALID_PAYLOAD'); }

			return $this->getDocument((int)$saved->getId());
		});
	}

	public function getDocument(int $id): RegistryResult
	{
		try
		{
			$row = DocumentTable::getById($id)->fetch();
			if (!$row) { return self::error('NOT_FOUND_OR_DENIED'); }
			$versions = [];
			foreach (VersionTable::getList(['filter' => ['=DOCUMENT_ID' => $id], 'order' => ['NUMBER' => 'ASC']])->fetchAll() as $version)
			{
				$versions[] = self::mapVersion($version);
			}

			return (new RegistryResult())->setDocument(new CatalogDocument((int)$row['ID'], $row['NAME'], $row['ARCHIVED'] === 'Y',
				$row['ORIGIN_MODULE'], $row['ORIGIN_ID'] === null ? null : (int)$row['ORIGIN_ID'], self::immutableDate($row['CREATED_AT']),
				(int)$row['CREATED_BY'], $versions));
		}
		catch (\Throwable) { return self::error('STORAGE_ERROR'); }
	}

	public function getVersion(int $documentId, int $versionId): RegistryResult
	{
		try
		{
			$document = DocumentTable::query()->setSelect(['ID'])->where('ID', $documentId)->fetch();
			if (!$document) { return self::error('NOT_FOUND_OR_DENIED'); }
			$row = VersionTable::query()
				->setSelect(['*'])
				->where('ID', $versionId)
				->where('DOCUMENT_ID', $documentId)
				->setLimit(1)
				->fetch()
			;
			if (!$row) { return self::error('NOT_FOUND_OR_DENIED'); }

			return (new RegistryResult())->setVersions([self::mapVersion($row)]);
		}
		catch (\Throwable) { return self::error('STORAGE_ERROR'); }
	}

	public function saveDraft(CatalogVersion $version, ?int $expectedRevision = null): RegistryResult
	{
		if ($version->state !== 'draft' || $version->documentId === null) { return self::error('VERSION_IMMUTABLE'); }

		return $this->transaction(function () use ($version, $expectedRevision): RegistryResult {
			if (!self::currentRow(DocumentTable::class, $version->documentId)) { return self::error('NOT_FOUND_OR_DENIED'); }
			$document = self::currentRow(DocumentTable::class, $version->documentId);
			if ($document['ARCHIVED'] === 'Y') { return self::error('INVALID_STATE'); }
			$revision = 1;
			if ($version->id !== null)
			{
				if (!self::currentRow(VersionTable::class, $version->id)) { return self::error('NOT_FOUND_OR_DENIED'); }
				$row = self::currentRow(VersionTable::class, $version->id);
				if ((int)$row['DOCUMENT_ID'] !== $version->documentId) { return self::error('NOT_FOUND_OR_DENIED'); }
				if ($row['STATE'] !== 'draft') { return self::error('VERSION_IMMUTABLE'); }
				if ((int)$row['REVISION'] !== $expectedRevision) { return self::error('REVISION_CONFLICT'); }
				$revision = $expectedRevision + 1;
			}
			$data = ['REVISION' => $revision, 'STATE' => 'draft', 'LANGUAGE_ID' => $version->languageId,
				'TEMPLATE' => $version->template, 'LABEL' => $version->label, 'CONTENT_HASH' => $version->contentHash,
				'SUBSTITUTION_SCHEMA' => json_encode((object)$version->substitutionSchema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
				'EXPIRY_RULE' => json_encode($version->expiryRule, JSON_THROW_ON_ERROR), 'DISPLAY_URL' => $version->displayUrl,
				'VALID_FROM' => self::nativeDate($version->validFrom), 'VALID_TO' => self::nativeDate($version->validTo)];
			if ($version->id === null)
			{
				$last = self::currentQuery(VersionTable::class)
					->setSelect(['NUMBER'])
					->where('DOCUMENT_ID', $version->documentId)
					->setOrder(['NUMBER' => 'DESC'])
					->setLimit(1)
					->fetch()
				;
				$data += ['DOCUMENT_ID' => $version->documentId, 'NUMBER' => $last ? (int)$last['NUMBER'] + 1 : 1];
				$saved = VersionTable::add($data);
			}
			else { $saved = VersionTable::update($version->id, $data); }
			if (!$saved->isSuccess()) { return self::error('INVALID_PAYLOAD'); }
			$id = $version->id ?? (int)$saved->getId();

			return (new RegistryResult())->setVersions([self::mapVersion(self::currentRow(VersionTable::class, $id))]);
		});
	}

	public function publish(int $versionId, int $expectedRevision, int $actorId): RegistryResult
	{
		if ($actorId <= 0 || $actorId > 2147483647) { return self::error('INVALID_PAYLOAD'); }

		return $this->transaction(function () use ($versionId, $expectedRevision, $actorId): RegistryResult {
			$initial = VersionTable::getById($versionId)->fetch();
			if (!$initial || !self::currentRow(DocumentTable::class, (int)$initial['DOCUMENT_ID'])
				|| !self::currentRow(VersionTable::class, $versionId)) { return self::error('NOT_FOUND_OR_DENIED'); }
			$row = self::currentRow(VersionTable::class, $versionId);
			if ((int)$row['DOCUMENT_ID'] !== (int)$initial['DOCUMENT_ID']) { return self::error('REVISION_CONFLICT'); }
			if ($row['STATE'] !== 'draft') { return self::error('VERSION_IMMUTABLE'); }
			if ((int)$row['REVISION'] !== $expectedRevision) { return self::error('REVISION_CONFLICT'); }
			if (self::currentRow(DocumentTable::class, (int)$row['DOCUMENT_ID'])['ARCHIVED'] === 'Y') { return self::error('INVALID_STATE'); }
			$saved = VersionTable::update($versionId, ['STATE' => 'published', 'REVISION' => $expectedRevision + 1,
				'PUBLISHED_AT' => new DateTime(), 'PUBLISHED_BY' => $actorId]);
			if (!$saved->isSuccess()) { return self::error('INVALID_PAYLOAD'); }

			return (new RegistryResult())->setVersions([self::mapVersion(self::currentRow(VersionTable::class, $versionId))]);
		});
	}

	public function archive(int $documentId): RegistryResult
	{
		return $this->transaction(function () use ($documentId): RegistryResult {
			if (!self::currentRow(DocumentTable::class, $documentId)) { return self::error('NOT_FOUND_OR_DENIED'); }
			$saved = DocumentTable::update($documentId, ['ARCHIVED' => 'Y']);
			if (!$saved->isSuccess()) { return self::error('STORAGE_ERROR'); }

			return $this->getDocument($documentId);
		});
	}

	public static function mapVersion(array $row): CatalogVersion
	{
		$rule = json_decode($row['EXPIRY_RULE'], true, 512, JSON_THROW_ON_ERROR);

		return new CatalogVersion((int)$row['ID'], (int)$row['DOCUMENT_ID'], (int)$row['NUMBER'], (int)$row['REVISION'],
			$row['STATE'], $row['LANGUAGE_ID'], $row['TEMPLATE'], $row['LABEL'], json_decode($row['SUBSTITUTION_SCHEMA'], true, 512, JSON_THROW_ON_ERROR),
			$row['CONTENT_HASH'], new ExpiryRule($rule['kind'], $rule['unit'] ?? null, $rule['amount'] ?? null, $rule['date'] ?? null),
			self::immutableDate($row['VALID_FROM']), self::immutableDate($row['VALID_TO']), $row['DISPLAY_URL'],
			self::immutableDate($row['PUBLISHED_AT']), $row['PUBLISHED_BY'] === null ? null : (int)$row['PUBLISHED_BY']);
	}

	private static function immutableDate(?DateTime $date): ?\DateTimeImmutable
	{
		return $date === null ? null : (new \DateTimeImmutable('@' . $date->getTimestamp()))->setTimezone(new \DateTimeZone('UTC'));
	}

	private static function nativeDate(?\DateTimeImmutable $date): ?DateTime
	{
		return $date === null ? null : DateTime::createFromTimestamp($date->getTimestamp());
	}

	private function transaction(callable $callback): RegistryResult
	{
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		$connection = Application::getConnection();
		$started = false;
		try
		{
			$connection->startTransaction();
			$started = true;
			$result = $callback();
			if (!$result->isSuccess())
			{
				$started = false;
				$this->rollback();

				return $result;
			}
			$started = false;
			$connection->commitTransaction();

			return $result;
		}
		catch (\Throwable)
		{
			if ($started)
			{
				try { $this->rollback(); }
				catch (\Throwable) { /* Return only the safe storage error. */ }
			}

			return self::error('STORAGE_ERROR');
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	private function rollback(): void
	{
		try { Application::getConnection()->rollbackTransaction(); }
		catch (\Bitrix\Main\DB\TransactionException $exception)
		{
			// MySQL has already rolled back the savepoint before this native exception.
			if ($exception->getMessage() !== 'Nested rollbacks are unsupported.') { throw $exception; }
		}
	}

	private static function error(string $code): RegistryResult
	{
		return (new RegistryResult())->addError(new \Bitrix\Main\Error('', $code));
	}

	/** Fetch current locked values through native ORM binary/date modifiers, never a snapshot reread. */
	private static function currentRows(string $model, array $filter): array
	{
		return self::currentQuery($model)->setSelect(['*'])->setFilter($filter)->setOrder(['ID' => 'ASC'])->fetchAll();
	}

	private static function currentQuery(string $model): \Bitrix\Main\ORM\Query\Query
	{
		$query = new class($model::getEntity()) extends \Bitrix\Main\ORM\Query\Query {
			protected function query($sql)
			{
				// Native Query has no locking setter. Use its SQL branch, bypassing caches/NoSQL/slaves.
				$result = $this->entity->getConnection()->query($sql . ' FOR UPDATE');
				$result->setReplacedAliases($this->replaced_aliases);
				if ($this->isFetchModificationRequired())
				{
					$result->addFetchDataModifier([$this, 'fetchDataModificationCallback']);
				}

				return $result;
			}
		};

		return $query;
	}

	private static function currentRow(string $model, int $id): array|false
	{
		if ($id <= 0 || $id > 2147483647) { return false; }

		return self::currentRows($model, ['=ID' => $id])[0] ?? false;
	}
}
