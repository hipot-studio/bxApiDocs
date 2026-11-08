<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\Document\Vibeoffice\Models\PlatformSessionTable;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UuidGenerator;

class SessionRegistry
{
	/** A refusal of the registry the open path cannot repeat its way out of. */
	public const ERROR_CODE_FAILED = 'vibeoffice_registry_failed';

	/**
	 * A refusal the document is merely busy with: the metadata of the open could not be written
	 * while a save of the same document held the session context. Before the retry cycle such a
	 * write was best-effort and never failed an open, so it is answered with the screen of the
	 * branch of saving instead of the generic failure.
	 */
	public const ERROR_CODE_BUSY = 'vibeoffice_registry_busy';

	private const MAX_EVENT_SESSIONS = 2000;
	private const OPEN_TOKEN_MAX_AGE_SECONDS = 7 * 24 * 3600;

	public function __construct(private readonly DocumentStateRepository $stateRepository = new DocumentStateRepository())
	{
	}

	/** Resume an ambiguous live attempt of the same local session, or reserve a new one. */
	public function beginOrResumeOpen(DocumentSession $session, string $hostDocKey): Result
	{
		if (!$session->getId() || HostDocumentKey::resolve($session) !== $hostDocKey)
		{
			return $this->failure('Invalid Vibeoffice open binding.');
		}

		$binding = $this->stateRepository->ensureSessionState($session);
		if (!$binding->isSuccess())
		{
			return $binding;
		}

		$result = $this->stateRepository->withLockedDocument($hostDocKey, function () use ($session, $hostDocKey): Result {
			$query = DocumentSessionTable::query()->setSelect(['*'])->where('ID', $session->getId());
			$row = $this->fetchLocked($query);
			$fresh = $row ? DocumentSession::buildFromArray($row) : null;
			if (!$fresh || !$fresh->isActive() || HostDocumentKey::resolve($fresh) !== $hostDocKey)
			{
				return $this->failure('Vibeoffice session is no longer active.');
			}

			$pending = $this->fetchLocked(PlatformSessionTable::query()
				->setSelect(['ID', 'OPEN_TOKEN', 'CREATE_TIME'])
				->where('HOST_DOC_KEY', $hostDocKey)
				->where('DOCUMENT_SESSION_ID', $fresh->getId())
				->where('STATUS', PlatformSessionTable::STATUS_PENDING)
				->whereNotNull('OPEN_TOKEN')
				->setOrder(['ID' => 'DESC'])
				->setLimit(1));
			if ($pending)
			{
				if ($pending['CREATE_TIME'] instanceof DateTime
					&& time() - $pending['CREATE_TIME']->getTimestamp() >= self::OPEN_TOKEN_MAX_AGE_SECONDS)
				{
					$deleted = PlatformSessionTable::delete((int)$pending['ID']);
					if (!$deleted->isSuccess())
					{
						return (new Result())->addErrors($deleted->getErrors());
					}

					return (new Result())->setData(['expired' => true]);
				}

				return (new Result())->setData(['openToken' => $pending['OPEN_TOKEN']]);
			}

			$token = UuidGenerator::generateV4();
			$add = PlatformSessionTable::add([
				'HOST_DOC_KEY' => $hostDocKey,
				'DOCUMENT_SESSION_ID' => $fresh->getId(),
				'OPEN_TOKEN' => $token,
				'STATUS' => PlatformSessionTable::STATUS_PENDING,
			]);

			return $add->isSuccess()
				? (new Result())->setData(['openToken' => $token])
				: (new Result())->addErrors($add->getErrors());
		});

		return $result->isSuccess() && ($result->getData()['expired'] ?? false)
			? (new Result())->addError(new Error('Vibeoffice open operation expired.', 'open_operation_expired'))
			: $result;
	}

	/** Reserve once before the HTTP retry loop. No network calls may run inside repository locks. */
	public function beginOpen(DocumentSession $session, string $hostDocKey): Result
	{
		if (!$session->getId() || HostDocumentKey::resolve($session) !== $hostDocKey)
		{
			return $this->failure('Invalid Vibeoffice open binding.');
		}

		$binding = $this->stateRepository->ensureSessionState($session);
		if (!$binding->isSuccess())
		{
			return $binding;
		}

		return $this->stateRepository->withLockedDocument($hostDocKey, function () use ($session, $hostDocKey): Result {
			$query = DocumentSessionTable::query()->setSelect(['*'])->where('ID', $session->getId());
			$row = $this->fetchLocked($query);
			$fresh = $row ? DocumentSession::buildFromArray($row) : null;
			if (!$fresh || $fresh->isNonActive() || HostDocumentKey::resolve($fresh) !== $hostDocKey)
			{
				return $this->failure('Vibeoffice session is no longer active.');
			}
			$token = UuidGenerator::generateV4();
			$add = PlatformSessionTable::add([
				'HOST_DOC_KEY' => $hostDocKey,
				'DOCUMENT_SESSION_ID' => $fresh->getId(),
				'OPEN_TOKEN' => $token,
				'STATUS' => PlatformSessionTable::STATUS_PENDING,
			]);

			return $add->isSuccess()
				? (new Result())->setData(['openToken' => $token])
				: (new Result())->addErrors($add->getErrors());
		});
	}

	/** Returns terminal=true and eligible sessionIds when a close won the race with open. */
	public function completeOpen(string $openToken, array $metadata): Result
	{
		$uuid = $this->normalizeUuid($metadata['session_id'] ?? null);
		$editorKey = $metadata['editor_key'] ?? null;
		$rev = $metadata['rev'] ?? null;
		if (!$uuid || !$this->isEditorKey($editorKey) || !$this->isRevision($rev))
		{
			return $this->failure('Invalid Vibeoffice open metadata.');
		}

		return $this->withOpenToken($openToken, function (array $pending) use ($uuid, $editorKey, $rev): Result {
			$hostDocKey = $pending['HOST_DOC_KEY'];
			$sessionId = (int)$pending['DOCUMENT_SESSION_ID'];
			if ($pending['STATUS'] !== PlatformSessionTable::STATUS_PENDING)
			{
				if ($pending['API_SESSION_ID'] !== $uuid)
				{
					return $this->failure('Vibeoffice open was already completed with another UUID.');
				}

				return $this->completionResult($pending, $sessionId);
			}

			$query = DocumentSessionTable::query()->setSelect(['*'])->where('ID', $sessionId);
			$sessionRow = $this->fetchLocked($query);
			$session = $sessionRow ? DocumentSession::buildFromArray($sessionRow) : null;
			if (!$session || !$session->isActive())
			{
				return $this->failure('Vibeoffice session is no longer active.');
			}

			$known = $this->findUuid($uuid);
			if ($known && (
				$known['HOST_DOC_KEY'] !== $hostDocKey
				|| ((int)$known['DOCUMENT_SESSION_ID'] > 0 && (int)$known['DOCUMENT_SESSION_ID'] !== $sessionId)
			))
			{
				return $this->failure('Vibeoffice UUID is already bound to another session.');
			}

			$terminal = ($known && $known['STATUS'] === PlatformSessionTable::STATUS_TERMINATED)
				|| $this->isEditorGenerationTerminated($hostDocKey, $editorKey, $rev);
			$fields = [
				'API_SESSION_ID' => $uuid,
				'DOCUMENT_SESSION_ID' => $sessionId,
				'OPEN_TOKEN' => $pending['OPEN_TOKEN'],
				'EDITOR_KEY' => $editorKey,
				'REV' => $rev,
				'STATUS' => $terminal ? PlatformSessionTable::STATUS_TERMINATED : PlatformSessionTable::STATUS_ACTIVE,
				'UPDATE_TIME' => new DateTime(),
			];
			if ($known)
			{
				$delete = PlatformSessionTable::delete($pending['ID']);
				if (!$delete->isSuccess())
				{
					return (new Result())->addErrors($delete->getErrors());
				}
			}
			$update = PlatformSessionTable::update($known['ID'] ?? $pending['ID'], $fields);
			if (!$update->isSuccess())
			{
				return (new Result())->addErrors($update->getErrors());
			}

			if (!(new DocumentSessionManager())->persistSessionMetadata($session, [
				'session_id' => $uuid,
				'editor_key' => $editorKey,
				'rev' => $rev,
			]))
			{
				return $this->failure('Could not persist Vibeoffice open metadata.', self::ERROR_CODE_BUSY);
			}

			return $this->completionResult($fields + $pending, $sessionId);
		});
	}

	public function cancelOpen(string $openToken): Result
	{
		return $this->withOpenToken($openToken, function (array $row): Result {
			if ($row['STATUS'] !== PlatformSessionTable::STATUS_PENDING)
			{
				return (new Result())->setData(['sessionIds' => []]);
			}

			$result = PlatformSessionTable::delete($row['ID']);

			return $result->isSuccess()
				? (new Result())->setData(['sessionIds' => $this->eligibleSessionIds([(int)$row['DOCUMENT_SESSION_ID']])])
				: (new Result())->addErrors($result->getErrors());
		});
	}

	/** Explicit UUID termination is authoritative in this host key, regardless of editor_key. */
	public function applyClosed(string $hostDocKey, array $terminated, array $retained): Result
	{
		$terminated = $this->normalizeUuids($terminated);
		$retained = $this->normalizeUuids($retained);
		if ($terminated === null || $retained === null || array_intersect($terminated, $retained))
		{
			return $this->failure('Invalid Vibeoffice closed session lists.');
		}

		return $this->stateRepository->withLockedDocument($hostDocKey, function () use ($hostDocKey, $terminated, $retained): Result {
			$sessionIds = [];
			foreach ([PlatformSessionTable::STATUS_TERMINATED => $terminated, PlatformSessionTable::STATUS_ACTIVE => $retained] as $status => $uuids)
			{
				foreach ($uuids as $uuid)
				{
					$row = $this->findUuid($uuid);
					if ($row && $row['HOST_DOC_KEY'] !== $hostDocKey)
					{
						return $this->failure('Vibeoffice UUID belongs to another document.');
					}
					if ($row)
					{
						if ($status === PlatformSessionTable::STATUS_TERMINATED)
						{
							$sessionIds[] = (int)$row['DOCUMENT_SESSION_ID'];
							$result = PlatformSessionTable::update($row['ID'], [
								'STATUS' => $status,
								'UPDATE_TIME' => new DateTime(),
							]);
						}
						else
						{
							continue;
						}
					}
					else
					{
						$result = PlatformSessionTable::add([
							'HOST_DOC_KEY' => $hostDocKey,
							'API_SESSION_ID' => $uuid,
							'STATUS' => $status,
						]);
					}
					if (!$result->isSuccess())
					{
						return (new Result())->addErrors($result->getErrors());
					}
				}
			}

			return (new Result())->setData(['sessionIds' => $this->eligibleSessionIds($sessionIds)]);
		});
	}

	/** A marker without a UUID also fences late open responses from this exact editor generation. */
	public function terminateEditorGeneration(string $hostDocKey, string $editorKey, ?int $rev = null): Result
	{
		if (!$this->isEditorKey($editorKey) || !$this->isRevision($rev))
		{
			return $this->failure('Invalid Vibeoffice editor generation.');
		}

		return $this->stateRepository->withLockedDocument($hostDocKey, function () use ($hostDocKey, $editorKey, $rev): Result {
			$query = $this->editorQuery($hostDocKey, $editorKey, $rev);
			$rows = $this->queryLocked($query);
			$sessionIds = [];
			$hasMarker = false;
			foreach ($rows as $row)
			{
				$hasMarker = $hasMarker || (!$row['API_SESSION_ID'] && !$row['OPEN_TOKEN'] && !$row['DOCUMENT_SESSION_ID']);
				$sessionIds[] = (int)$row['DOCUMENT_SESSION_ID'];
				$result = PlatformSessionTable::update($row['ID'], [
					'STATUS' => PlatformSessionTable::STATUS_TERMINATED,
					'UPDATE_TIME' => new DateTime(),
				]);
				if (!$result->isSuccess())
				{
					return (new Result())->addErrors($result->getErrors());
				}
			}
			if (!$hasMarker)
			{
				$result = PlatformSessionTable::add([
					'HOST_DOC_KEY' => $hostDocKey,
					'EDITOR_KEY' => $editorKey,
					'REV' => $rev,
					'STATUS' => PlatformSessionTable::STATUS_TERMINATED,
				]);
				if (!$result->isSuccess())
				{
					return (new Result())->addErrors($result->getErrors());
				}
			}

			return (new Result())->setData(['sessionIds' => $this->eligibleSessionIds($sessionIds)]);
		});
	}

	public function hasLiveSessions(int $documentSessionId): bool
	{
		return (bool)$this->fetchLocked(PlatformSessionTable::query()
			->setSelect(['ID'])
			->where('DOCUMENT_SESSION_ID', $documentSessionId)
			->where($this->liveFilter())
			->setLimit(1));
	}

	public function hasLiveSessionsForHash(string $externalHash): bool
	{
		$sessions = DocumentSessionTable::query()
			->setSelect(['ID'])
			->where('EXTERNAL_HASH', $externalHash)
			->where('SERVICE', DocumentService::Vibeoffice->value);

		return (bool)$this->fetchLocked(PlatformSessionTable::query()
			->setSelect(['ID'])
			->whereIn('DOCUMENT_SESSION_ID', $sessions)
			->where($this->liveFilter())
			->setLimit(1));
	}

	/** Mapped local IDs of every status; callers must check liveness before finalizing them. */
	public function getSessionIds(string $hostDocKey, ?string $editorKey = null): array
	{
		$query = PlatformSessionTable::query()->setSelect(['DOCUMENT_SESSION_ID'])->where('HOST_DOC_KEY', $hostDocKey);
		if ($editorKey !== null)
		{
			$query->where('EDITOR_KEY', $editorKey);
		}

		return array_values(array_unique(array_filter(array_map(
			static fn(array $row): int => (int)$row['DOCUMENT_SESSION_ID'],
			$query->fetchAll(),
		))));
	}

	/** Batches are a memory bound, not a limit on retained history. */
	public function getSessionIdBatches(string $hostDocKey): \Generator
	{
		$lastId = 0;
		do
		{
			$rows = PlatformSessionTable::query()->setSelect(['ID', 'DOCUMENT_SESSION_ID'])
				->where('HOST_DOC_KEY', $hostDocKey)->whereNotNull('DOCUMENT_SESSION_ID')
				->where('ID', '>', $lastId)->setOrder(['ID' => 'ASC'])->setLimit(100)->fetchAll();
			if ($rows)
			{
				$lastId = (int)end($rows)['ID'];
				yield array_values(array_unique(array_map('intval', array_column($rows, 'DOCUMENT_SESSION_ID'))));
			}
		}
		while (count($rows) === 100);
	}

	public function getRecipientSessionIds(string $hostDocKey, string $editorKey, int $rev): array
	{
		$queries = [
			PlatformSessionTable::query()->where('HOST_DOC_KEY', $hostDocKey)->where($this->liveFilter()),
			$this->editorQuery($hostDocKey, $editorKey, $rev)->whereIn('STATUS', [
				PlatformSessionTable::STATUS_ACTIVE, PlatformSessionTable::STATUS_PENDING, PlatformSessionTable::STATUS_TERMINATED,
			]),
		];
		$ids = [];
		foreach ($queries as $query)
		{
			$rows = $query->setSelect(['DOCUMENT_SESSION_ID'])->setDistinct()
				->whereNotNull('DOCUMENT_SESSION_ID')->fetchAll();
			foreach ($rows as $row)
			{
				$id = (int)$row['DOCUMENT_SESSION_ID'];
				$ids[$id] = $id;
			}
		}

		return array_values($ids);
	}

	/** A cancelled HTTP attempt alone does not prove that its local session is terminal. */
	public function getTerminatedSessionIds(array $sessionIds): array
	{
		if ($sessionIds === [])
		{
			return [];
		}
		$rows = PlatformSessionTable::query()->setSelect(['DOCUMENT_SESSION_ID'])
			->whereIn('DOCUMENT_SESSION_ID', $sessionIds)
			->where('STATUS', PlatformSessionTable::STATUS_TERMINATED)
			->whereNotNull('API_SESSION_ID')->fetchAll();

		return array_values(array_unique(array_map('intval', array_column($rows, 'DOCUMENT_SESSION_ID'))));
	}

	private function withOpenToken(string $openToken, callable $mutation): Result
	{
		if (!$this->normalizeUuid($openToken))
		{
			return $this->failure('Invalid Vibeoffice open token.');
		}
		$row = PlatformSessionTable::query()->setSelect(['HOST_DOC_KEY'])->where('OPEN_TOKEN', $openToken)->fetch();
		if (!$row)
		{
			return $this->failure('Vibeoffice open reservation was not found.');
		}

		return $this->stateRepository->withLockedDocument($row['HOST_DOC_KEY'], function () use ($openToken, $mutation): Result {
			$row = $this->fetchLocked(PlatformSessionTable::query()->setSelect(['*'])->where('OPEN_TOKEN', $openToken));

			return $row ? $mutation($row) : $this->failure('Vibeoffice open reservation was not found.');
		});
	}

	private function completionResult(array $row, int $sessionId): Result
	{
		return (new Result())->setData([
			'terminal' => $row['STATUS'] === PlatformSessionTable::STATUS_TERMINATED,
			'sessionIds' => $this->eligibleSessionIds([$sessionId]),
			'hostDocKey' => $row['HOST_DOC_KEY'],
		]);
	}

	private function liveFilter(): \Bitrix\Main\ORM\Query\Filter\ConditionTree
	{
		return Query::filter()->logic('or')
			->where('STATUS', PlatformSessionTable::STATUS_ACTIVE)
			->where('STATUS', PlatformSessionTable::STATUS_PENDING);
	}

	private function eligibleSessionIds(array $sessionIds): array
	{
		return array_values(array_filter(array_unique($sessionIds), fn(int $id): bool => $id > 0 && !$this->hasLiveSessions($id)));
	}

	private function findUuid(string $uuid): ?array
	{
		return $this->fetchLocked(PlatformSessionTable::query()->setSelect(['*'])->where('API_SESSION_ID', $uuid));
	}

	private function editorQuery(string $hostDocKey, string $editorKey, ?int $rev): Query
	{
		$query = PlatformSessionTable::query()->setSelect(['*'])->where('HOST_DOC_KEY', $hostDocKey)->where('EDITOR_KEY', $editorKey);
		if ($rev !== null)
		{
			$query->where('REV', $rev);
		}

		return $query;
	}

	private function isEditorGenerationTerminated(string $hostDocKey, string $editorKey, ?int $rev): bool
	{
		$revision = Query::filter()->whereNull('REV');
		if ($rev !== null)
		{
			$revision->logic('or')->where('REV', $rev);
		}
		$query = $this->editorQuery($hostDocKey, $editorKey, null)->setSelect(['ID'])
			->whereNull('API_SESSION_ID')->whereNull('OPEN_TOKEN')->whereNull('DOCUMENT_SESSION_ID')
			->where('STATUS', PlatformSessionTable::STATUS_TERMINATED)->where($revision)->setLimit(1);

		return $this->fetchLocked($query) !== null;
	}

	private function fetchLocked(Query $query): ?array
	{
		return DocumentStateRepository::fetchForUpdate($query);
	}

	private function queryLocked(Query $query): array
	{
		return DocumentStateRepository::fetchAllForUpdate($query);
	}

	private function normalizeUuid(mixed $uuid): ?string
	{
		return is_string($uuid) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di', $uuid)
			? strtolower($uuid) : null;
	}

	private function normalizeUuids(array $uuids): ?array
	{
		if (count($uuids) > self::MAX_EVENT_SESSIONS)
		{
			return null;
		}
		$result = [];
		foreach ($uuids as $uuid)
		{
			$uuid = $this->normalizeUuid($uuid);
			if (!$uuid)
			{
				return null;
			}
			$result[$uuid] = $uuid;
		}

		return array_values($result);
	}

	private function isEditorKey(mixed $editorKey): bool
	{
		return is_string($editorKey) && $editorKey !== '' && strlen($editorKey) <= 255;
	}

	private function isRevision(mixed $rev): bool
	{
		return $rev === null || (is_int($rev) && $rev >= 0 && $rev <= 2_147_483_647);
	}

	private function failure(string $message, string $code = self::ERROR_CODE_FAILED): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}
}
