<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\LegalRegistry;

use Bitrix\Crm\V2\Internal\Model\LegalRegistry\DocumentTable;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\VersionTable;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\SourceTable;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\UsageTable;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\RegistryResult;
use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;

/** Internal persistence only; these rows require ACL and DTO mapping before public use. */
final class UsageRepository
{
	public function ensureSource(int $formId, string $nameSnapshot): RegistryResult
	{
		if ($formId <= 0 || $formId > 2147483647) { return self::error('INVALID_PAYLOAD'); }

		return $this->ensure($formId, $nameSnapshot, true);
	}

	private function ensure(int $formId, string $nameSnapshot, bool $retryDuplicate): RegistryResult
	{
		$duplicate = false;
		$result = $this->transaction(function () use ($formId, $nameSnapshot, &$duplicate): RegistryResult {
			$source = self::currentRows(SourceTable::class, ['=FORM_ID' => $formId])[0] ?? false;
			if ($source)
			{
				if (!self::currentRow(SourceTable::class, (int)$source['ID'])) { return self::error('STORAGE_ERROR'); }

				return (new RegistryResult())->setReplay(true);
			}
			try { $saved = SourceTable::add(['FORM_ID' => $formId, 'NAME_SNAPSHOT' => $nameSnapshot, 'STATE' => 'active', 'REVISION' => 1]); }
			catch (\Bitrix\Main\DB\DuplicateEntryException) { $duplicate = true;

return self::error('STORAGE_ERROR'); }

			return $saved->isSuccess() ? new RegistryResult() : self::error('INVALID_PAYLOAD');
		});

		return $duplicate && $retryDuplicate ? $this->ensure($formId, $nameSnapshot, false) : $result;
	}

	/** @return list<array> */
	public function readHistory(int $formId): array
	{

		try
		{
			$source = SourceTable::getList(['filter' => ['=FORM_ID' => $formId]])->fetch();

			return $source ? UsageTable::getList(['filter' => ['=SOURCE_ID' => (int)$source['ID']], 'order' => ['ID' => 'ASC']])->fetchAll() : [];
		}
		catch (\Throwable) { throw new \RuntimeException('STORAGE_ERROR'); }
	}

	public function changeUsage(int $formId, string $operation, ?int $documentId, ?int $versionId,
		bool $checked, bool $required, int $expectedSourceRevision, ?string $formNameSnapshot = null): RegistryResult
	{
		if (!in_array($operation, ['bind', 'replace', 'unbind', 'closeSource'], true)) { return self::error('INVALID_PAYLOAD'); }

		return $this->transaction(function () use ($formId, $operation, $documentId, $versionId, $checked, $required, $expectedSourceRevision, $formNameSnapshot): RegistryResult {
			$source = self::currentRows(SourceTable::class, ['=FORM_ID' => $formId])[0] ?? false;
			if (!$source || !self::currentRow(SourceTable::class, (int)$source['ID'])) { return self::error('NOT_FOUND_OR_DENIED'); }
			$source = self::currentRow(SourceTable::class, (int)$source['ID']);
			if ($operation === 'closeSource' && $source['STATE'] !== 'active') { return (new RegistryResult())->setReplay(true); }
			if ((int)$source['REVISION'] !== $expectedSourceRevision) { return self::error('REVISION_CONFLICT'); }
			if ($source['STATE'] !== 'active') { return self::error('INVALID_STATE'); }
			if ($operation !== 'closeSource' && ($documentId === null || $documentId <= 0)) { return self::error('INVALID_PAYLOAD'); }
			$filter = ['=SOURCE_ID' => (int)$source['ID'], '=DATE_END' => null];
			if ($operation !== 'closeSource') { $filter['=DOCUMENT_ID'] = $documentId; }
			$open = self::currentRows(UsageTable::class, $filter);
			if (($operation === 'bind' && $open !== []) || ($operation === 'replace' && count($open) !== 1)) { return self::error('INVALID_STATE'); }
			$sourceChanges = ['REVISION' => $expectedSourceRevision + 1,
				'STATE' => $operation === 'closeSource' ? 'inactive' : 'active'];
			$now = new DateTime();
			if (in_array($operation, ['bind', 'replace'], true))
			{
				if ($versionId === null || !self::currentRow(DocumentTable::class, $documentId)) { return self::error('INVALID_PAYLOAD'); }
				$version = self::currentRow(VersionTable::class, $versionId);
				$document = self::currentRow(DocumentTable::class, $documentId);
				if (!$version || (int)$version['DOCUMENT_ID'] !== $documentId || $version['STATE'] !== 'published'
					|| $document['ARCHIVED'] === 'Y'
					|| ($version['VALID_FROM'] !== null && $version['VALID_FROM']->getTimestamp() > $now->getTimestamp())
					|| ($version['VALID_TO'] !== null && $version['VALID_TO']->getTimestamp() < $now->getTimestamp())) { return self::error('INVALID_STATE'); }
			}
			foreach ($open as $row)
			{
				$saved = UsageTable::update((int)$row['ID'], ['DATE_END' => $now]);
				if (!$saved->isSuccess()) { return self::error('STORAGE_ERROR'); }
			}
			if (in_array($operation, ['bind', 'replace'], true))
			{
				$sourceChanges['NAME_SNAPSHOT'] = $formNameSnapshot ?? $source['NAME_SNAPSHOT'];
				$saved = UsageTable::add(['SOURCE_ID' => (int)$source['ID'], 'DOCUMENT_ID' => $documentId,
					'VERSION_ID' => $versionId, 'REVISION' => $expectedSourceRevision + 1, 'CHECKED' => $checked ? 'Y' : 'N',
					'REQUIRED' => $required ? 'Y' : 'N', 'DATE_START' => $now, 'FORM_NAME_SNAPSHOT' => $sourceChanges['NAME_SNAPSHOT']]);
				if (!$saved->isSuccess()) { return self::error('STORAGE_ERROR'); }
			}
			$saved = SourceTable::update((int)$source['ID'], $sourceChanges);

			return $saved->isSuccess() ? new RegistryResult() : self::error('STORAGE_ERROR');
		});
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

		return $query->setSelect(['*'])->setFilter($filter)->setOrder(['ID' => 'ASC'])->fetchAll();
	}

	private static function currentRow(string $model, int $id): array|false
	{
		if ($id <= 0 || $id > 2147483647) { return false; }

		return self::currentRows($model, ['=ID' => $id])[0] ?? false;
	}
}
