<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\LegalRegistry;

use Bitrix\Crm\V2\Internal\Model\LegalRegistry\SubjectTable;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\SubjectLinkTable;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\EventTable;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\RegistryResult;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\SubjectReference;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\SubjectTransition;
use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;

/** Internal storage mechanism; the caller verifies the trusted CRM lifecycle and access. */
final class SubjectRepository
{
	/** Caller owns the source/submission transaction before any subject locks. */
	public function resolveOrCreate(SubjectReference $original, array $bindings): int
	{

		try
		{
			$refs = [$original, ...$bindings];
			$ids = [];
			foreach ($refs as $ref)
			{
				if (!$ref instanceof SubjectReference) { throw new \UnexpectedValueException('SUBJECT_UNRESOLVED'); }
				foreach ($this->referenceIds($ref) as $id) { $ids[$id] = $id; }
			}
			$contacts = array_values(array_filter($bindings, static fn(SubjectReference $ref): bool => $ref->entityTypeId === 3));
			if (count($contacts) > 1)
			{
				foreach ($contacts as $contact) { if ($this->referenceIds($contact) === []) { throw new \UnexpectedValueException('SUBJECT_UNRESOLVED'); } }
			}
			$canonical = array_unique(array_map(fn(int $id): int => $this->canonicalId($id), array_values($ids)));
			if (count($canonical) > 1) { throw new \UnexpectedValueException('SUBJECT_UNRESOLVED'); }
			if ($canonical === [])
			{
				if (count($contacts) > 1 || ($contacts === [] && count($bindings) !== 1)) { throw new \UnexpectedValueException('SUBJECT_UNRESOLVED'); }
				$physical = $contacts[0] ?? $bindings[0];
				$uid = 'crm:' . $physical->entityTypeId . ':' . $physical->entityId;
				$connection = Application::getConnection();
				$connection->startTransaction();
				$createStarted = true;
				try
				{
					$saved = SubjectTable::add(['SUBJECT_UID' => $uid, 'CANONICAL_ID' => null, 'REVISION' => 1]);
					if (!$saved->isSuccess()) { throw new \RuntimeException('STORAGE_ERROR'); }
					$id = (int)$saved->getId();
					$createStarted = false; $connection->commitTransaction();
				}
				catch (\Bitrix\Main\DB\DuplicateEntryException)
				{
					$createStarted = false; $this->rollback();
					$row = self::currentRows(SubjectTable::class, ['=SUBJECT_UID' => $uid])[0] ?? false;
					if (!$row) { throw new \RuntimeException('STORAGE_ERROR'); }
					if ($row['CANONICAL_ID'] !== null) { throw new \UnexpectedValueException('SUBJECT_UNRESOLVED'); }
					$id = (int)$row['ID'];
				}
				catch (\Throwable $exception)
				{
					if ($createStarted) { $this->rollback(); }
					throw new \RuntimeException('STORAGE_ERROR');
				}
				$ids[$id] = $id;
			}
			else { $id = (int)reset($canonical); $ids[$id] = $id; }
			ksort($ids, SORT_NUMERIC);
			foreach ($ids as $subjectId)
			{
				$before = SubjectTable::getById($subjectId)->fetch();
				$current = self::currentRow(SubjectTable::class, $subjectId);
				if (!$current || ($before && (int)$before['REVISION'] !== (int)$current['REVISION']) || ($current['CANONICAL_ID'] !== null && (int)$current['CANONICAL_ID'] !== $id)) { throw new \UnexpectedValueException('SUBJECT_UNRESOLVED'); }
				if ($this->canonicalId($subjectId) !== $id) { throw new \UnexpectedValueException('SUBJECT_UNRESOLVED'); }
			}
			if ($this->referenceIds($original) === []) { $this->makeCurrent($id, $original); }
			foreach ($bindings as $ref) { $this->makeCurrent($id, $ref); }

			return $id;
		}
		catch (\UnexpectedValueException $exception) { throw $exception; }
		catch (\Throwable) { throw new \RuntimeException('STORAGE_ERROR'); }
	}

	public function canonicalId(int $id): int
	{

		try
		{
			$seen = [];
			while (true)
			{
				if (isset($seen[$id])) { throw new \UnexpectedValueException('SUBJECT_UNRESOLVED'); }
				$seen[$id] = true;
				$row = SubjectTable::getById($id)->fetch();
				if (!$row) { throw new \UnexpectedValueException('SUBJECT_UNRESOLVED'); }
				if ($row['CANONICAL_ID'] === null) { return $id; }
				$id = (int)$row['CANONICAL_ID'];
			}
		}
		catch (\UnexpectedValueException $exception) { throw $exception; }
		catch (\Throwable) { throw new \RuntimeException('STORAGE_ERROR'); }
	}

	/** @return list<int> */
	public function readAliases(int $subjectId): array
	{

		try
		{
			$id = $this->canonicalId($subjectId);
			$aliases = [$id]; $frontier = [$id]; $seen = [$id => true];
			while ($frontier !== [])
			{
				$rows = SubjectTable::getList(['select' => ['ID'], 'filter' => ['@CANONICAL_ID' => $frontier], 'order' => ['ID' => 'ASC']])->fetchAll();
				$frontier = [];
				foreach ($rows as $row)
				{
					$alias = (int)$row['ID'];
					if (isset($seen[$alias])) { throw new \UnexpectedValueException('SUBJECT_UNRESOLVED'); }
					$seen[$alias] = true; $frontier[] = $alias; $aliases[] = $alias;
				}
			}
			sort($aliases, SORT_NUMERIC);

			return $aliases;
		}
		catch (\UnexpectedValueException $exception) { throw $exception; }
		catch (\Throwable) { throw new \RuntimeException('STORAGE_ERROR'); }
	}

	public function readTransition(string $operationKey): ?array
	{

		try
		{
			$row = EventTable::getList(['filter' => ['=EVENT_KEY' => 'transition:' . $operationKey]])->fetch();

			return $row ? json_decode($row['PAYLOAD'], true, 512, JSON_THROW_ON_ERROR)['mapping'] : null;
		}
		catch (\UnexpectedValueException $exception) { throw $exception; }
		catch (\Throwable) { throw new \RuntimeException('STORAGE_ERROR'); }
	}

	public function transferSubjects(SubjectTransition $transition, int $actorId): RegistryResult
	{
		return $this->transfer($transition, $actorId, true);
	}

	private function transfer(SubjectTransition $transition, int $actorId, bool $retryDuplicate): RegistryResult
	{
		if (strlen('transition:' . $transition->operationKey) > 255 || $actorId <= 0) { return self::error('INVALID_PAYLOAD'); }
		$request = LedgerRepository::canonicalJson(['kind' => $transition->kind, 'from' => $transition->from, 'to' => $transition->to]);
		$duplicate = false;
		$result = $this->transaction(function () use ($transition, $actorId, $request, &$duplicate): RegistryResult {
			$key = 'transition:' . $transition->operationKey;
			$existing = EventTable::getList(['filter' => ['=EVENT_KEY' => $key]])->fetch();
			if ($existing)
			{
				$payload = json_decode($existing['PAYLOAD'], true, 512, JSON_THROW_ON_ERROR);

				return ($payload['request'] ?? null) === $request ? (new RegistryResult())->setReplay(true) : self::error('IDEMPOTENCY_CONFLICT');
			}
			$canonicalIds = [];
			foreach ($transition->from as $ref)
			{
				$ids = $this->referenceIds($ref);
				if ($ids === []) { return self::error('SUBJECT_UNRESOLVED'); }
				$canonical = array_unique(array_map(fn(int $id): int => $this->canonicalId($id), $ids));
				if (count($canonical) !== 1) { return self::error('SUBJECT_UNRESOLVED'); }
				$id = (int)reset($canonical); $canonicalIds[$id] = $id;
			}
			foreach ($transition->to as $ref)
			{
				$ids = $this->referenceIds($ref);
				$canonical = array_unique(array_map(fn(int $id): int => $this->canonicalId($id), $ids));
				if (count($canonical) > 1) { return self::error('SUBJECT_UNRESOLVED'); }
				foreach ($canonical as $id) { $canonicalIds[$id] = $id; }
			}
			$ids = [];
			foreach ($canonicalIds as $id) { foreach ($this->readAliases($id) as $alias) { $ids[$alias] = $alias; } }
			ksort($ids, SORT_NUMERIC);
			foreach ($ids as $id)
			{
				$before = SubjectTable::getById($id)->fetch();
				$current = self::currentRow(SubjectTable::class, $id);
				if (!$current) { return self::error('SUBJECT_UNRESOLVED'); }
				if (!$before || (int)$before['REVISION'] !== (int)$current['REVISION']) { return self::error('REVISION_CONFLICT'); }
			}
			foreach ($ids as $id)
			{
				if (!isset($canonicalIds[$this->canonicalId($id)])) { return self::error('REVISION_CONFLICT'); }
			}
			$existing = self::currentRows(EventTable::class, ['=EVENT_KEY' => $key])[0] ?? false;
			if ($existing)
			{
				$payload = json_decode($existing['PAYLOAD'], true, 512, JSON_THROW_ON_ERROR);

				return ($payload['request'] ?? null) === $request ? (new RegistryResult())->setReplay(true) : self::error('IDEMPOTENCY_CONFLICT');
			}
			$lockedAliases = [];
			foreach ($canonicalIds as $id) { foreach ($this->readAliases($id) as $alias) { $lockedAliases[$alias] = $alias; } }
			ksort($lockedAliases, SORT_NUMERIC);
			if ($lockedAliases !== $ids) { return self::error('REVISION_CONFLICT'); }
			// A deterministic existing root cannot introduce a canonical cycle.
			$target = min($canonicalIds);
			$prior = self::currentRows(SubjectLinkTable::class, ['@SUBJECT_ID' => array_values($ids)]);
			foreach ($prior as $link)
			{
				if ($link['IS_CURRENT'] === 'Y')
				{
					$saved = SubjectLinkTable::update((int)$link['ID'], ['IS_CURRENT' => 'N']);
					if (!$saved->isSuccess()) { return self::error('STORAGE_ERROR'); }
				}
			}
			foreach ($ids as $id)
			{
				$row = self::currentRow(SubjectTable::class, $id);
				$saved = SubjectTable::update($id, ['CANONICAL_ID' => $id === $target ? null : $target, 'REVISION' => (int)$row['REVISION'] + 1]);
				if (!$saved->isSuccess()) { return self::error('STORAGE_ERROR'); }
			}
			foreach ($transition->to as $ref) { $this->makeCurrent($target, $ref); }
			$mapping = ['canonicalId' => $target, 'subjectIds' => array_values($ids)];
			$now = new DateTime();
			try { $saved = EventTable::add(['SUBJECT_ID' => $target, 'EVENT_KEY' => $key, 'KIND' => $transition->kind,
				'ACTOR_ID' => $actorId, 'EVENT_DATE' => $now, 'SERVER_TIME' => $now,
				'PAYLOAD' => LedgerRepository::canonicalJson(['request' => $request, 'mapping' => $mapping, 'previousLinks' => $prior])]); }
			catch (\Bitrix\Main\DB\DuplicateEntryException) { $duplicate = true;

return self::error('STORAGE_ERROR'); }

			return $saved->isSuccess() ? new RegistryResult() : self::error('STORAGE_ERROR');
		});

		return $duplicate && $retryDuplicate ? $this->transfer($transition, $actorId, false) : $result;
	}

	private function referenceIds(SubjectReference $ref): array
	{
		$ids = [];
		foreach (SubjectLinkTable::getList(['filter' => ['=ENTITY_TYPE_ID' => $ref->entityTypeId, '=ENTITY_ID' => $ref->entityId]])->fetchAll() as $row)
		{
			$ids[(int)$row['SUBJECT_ID']] = (int)$row['SUBJECT_ID'];
		}

		return array_values($ids);
	}

	private function makeCurrent(int $id, SubjectReference $ref): void
	{
		$row = self::currentRows(SubjectLinkTable::class, ['=SUBJECT_ID' => $id, '=ENTITY_TYPE_ID' => $ref->entityTypeId, '=ENTITY_ID' => $ref->entityId])[0] ?? false;
		$saved = $row ? SubjectLinkTable::update((int)$row['ID'], ['IS_CURRENT' => 'Y'])
			: SubjectLinkTable::add(['SUBJECT_ID' => $id, 'ENTITY_TYPE_ID' => $ref->entityTypeId, 'ENTITY_ID' => $ref->entityId, 'IS_CURRENT' => 'Y']);
		if (!$saved->isSuccess()) { throw new \RuntimeException('STORAGE_ERROR'); }
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
