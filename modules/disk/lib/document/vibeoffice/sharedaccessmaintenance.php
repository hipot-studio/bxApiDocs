<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Vibeoffice\Models\DocumentStateTable;
use Bitrix\Disk\Document\Vibeoffice\Models\PlatformSessionTable;
use Bitrix\Disk\File;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Internals\ExternalLinkTable;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/** Expiry and retry have independent capacity; the unit of work is a host key and subject pair. */
final class SharedAccessMaintenance
{
	private const LIMIT = 100;
	private const METRIC_ROW_LIMIT = 1000;
	private const CURSOR_PREFIX = 'vibeoffice_shared_';

	public function __construct(
		private readonly DocumentStateRepository $repository = new DocumentStateRepository(),
		private readonly AccessRevocation $revocation = new AccessRevocation(),
	)
	{
	}

	public function run(DateTime $now, float $deadline): Result
	{
		$started = hrtime(true) / 1e9;
		$data = $this->backlogMetrics([], false, $now) + [
			'profileExceeded' => true,
			'markedCount' => 0, 'confirmedCount' => 0, 'latencySampleCount' => 0,
			'completeRevokeLatency' => null, 'arrivalRate' => 0.0, 'drainRate' => 0.0,
			'rateScope' => 'maintenance_pass', 'passSeconds' => 0.0,
		];
		if (!is_finite($deadline) || $deadline <= $started || DocumentStateRepository::hasOpenTransaction()
			|| DocumentStateRepository::holdsAnyLock() || !SharedEditingUsage::hasBeenUsed()
			|| !SharedEditingSchema::hasColumns())
		{
			return (new Result())->setData($data);
		}
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			$queues = ['retry' => [], 'expiry' => [], 'source' => []];
			$visited = $cursors = [];
			$httpSeconds = $maxHttpSeconds = 0.0;
			$failed = false;
			try
			{
				$presence = $this->queuePresence($now, $deadline);
				// Snapshot retries before marking expiry, so the new debt cannot consume both reservations.
				$prepared = SqlDeadlineScope::run($deadline, function () use ($presence, $now, $deadline, &$queues): Result {
					foreach (['retry', 'expiry'] as $kind)
					{
						if ($presence === null || $presence[$kind]) { $queues[$kind] = $this->candidates($kind, $now, $deadline); }
					}
					$queues['source'] = $this->sourceCandidates($now, $deadline);
					return new Result();
				});
				$failed = !$prepared->isSuccess();
				$processed = SqlDeadlineScope::run($deadline, function () use ($queues, $now, $deadline,
					&$data, &$visited, &$cursors, &$httpSeconds, &$maxHttpSeconds, &$failed): Result {
					for ($index = 0; $index < self::LIMIT && hrtime(true) / 1e9 < $deadline; $index++)
					{
						foreach (['expiry', 'retry', 'source'] as $kind)
						{
							if (!isset($queues[$kind][$index]) || $deadline - hrtime(true) / 1e9 < 1.1)
							{
								continue;
							}
							$row = $queues[$kind][$index];
							if ($kind === 'source')
							{
								$cursors[$kind] = (int)$row['ID'];
							}
							else
							{
								$field = $kind === 'expiry' ? 'ACCESS_EXPIRES_AT' : 'REVOKE_RETRY_AT';
								$cursors[$kind] = [$row[$field]?->getTimestamp(), (int)$row['ID']];
							}
							if ($kind === 'source' && $row['sourceValid']) { continue; }
							$pair = $row['HOST_DOC_KEY'] . "\0" . $row['PLATFORM_USER_ID'];
							if (isset($visited[$pair]))
							{
								continue;
							}
							$visited[$pair] = true;
							if ($kind !== 'retry')
							{
								$marked = $this->expire($row, $now, $deadline);
								$data['markedCount'] += (int)($marked->getData()['marked'] ?? false);
								if (!$marked->isSuccess())
								{
									$failed = true;
									continue;
								}
								if (!($marked->getData()['marked'] ?? false)) { continue; }
							}
							$result = $this->revocation->processBinding((int)$row['ID'], $deadline);
							$duration = (float)($result->getData()['requestSeconds'] ?? 0.0);
							$httpSeconds += $duration;
							$maxHttpSeconds = max($maxHttpSeconds, $duration);
							$failed = $failed || !$result->isSuccess();
							if (($result->getData()['confirmed'] ?? false) && ($result->getData()['changed'] ?? false))
							{
								$data['confirmedCount']++;
								$origin = $this->origin($row['HOST_DOC_KEY'], $row['PLATFORM_USER_ID'], time());
								if ($origin !== null)
								{
									$data['latencySampleCount']++;
									$data['completeRevokeLatency'] = max($data['completeRevokeLatency'] ?? 0, time() - $origin);
								}
							}
						}
					}
					return new Result();
				});
				$failed = $failed || !$processed->isSuccess();
				$observationNow = DateTime::createFromTimestamp(max($now->getTimestamp(), time()));
				$data = array_replace($data, $this->backlog($observationNow, $deadline));
			}
			catch (SqlQueryException)
			{
				$failed = true;
			}
			foreach ($cursors as $kind => $cursor)
			{
				Option::set('disk', self::CURSOR_PREFIX . $kind . '_cursor',
					$kind === 'source' ? (string)$cursor : json_encode($cursor));
			}
			$data['passSeconds'] = max(0.000001, hrtime(true) / 1e9 - $started);
			$data['arrivalRate'] = $data['markedCount'] / $data['passSeconds'];
			$data['drainRate'] = $data['confirmedCount'] / $data['passSeconds'];
			$exceeded = $failed || !$data['metricsComplete']
				|| (count($queues['expiry']) === self::LIMIT && $data['dueExpiryCountLowerBound'] > 0)
				|| (count($queues['retry']) === self::LIMIT && $data['dueRetryCountLowerBound'] > 0)
				|| $data['dueExpiryCount'] > self::LIMIT || $data['dueRetryCount'] > self::LIMIT
				|| $data['oldestDueAge'] > 120
				|| $data['completeRevokeLatency'] > 120 || $data['unknownAgeCount'] > 0
				|| $data['latencySampleCount'] < $data['confirmedCount'] || !SharedEditingSchema::hasRevokeTelemetry()
				|| $maxHttpSeconds > 0.2 || $httpSeconds > 40 || $data['passSeconds'] - $httpSeconds > 5
				|| hrtime(true) / 1e9 >= $deadline;
			$data['profileExceeded'] = $exceeded;
			MaintenanceAgent::logger()?->log($exceeded ? 'warning' : 'info', 'vibeoffice maintenance', [
				'operation' => 'shared_pass', 'result' => $exceeded ? 'incomplete' : 'ok',
				'code' => $exceeded ? 'shared_access_profile_exceeded' : null,
			] + $data);
			return (new Result())->setData($data);
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	/** Inspect at most one revoke-index page of outstanding grants without a retry reservation. */
	private function sourceCandidates(DateTime $now, float $deadline): array
	{
		if ($deadline - hrtime(true) / 1e9 < 1.1) { return []; }
		$cursor = max(0, (int)Option::get('disk', self::CURSOR_PREFIX . 'source_cursor', '0'));
		$query = PlatformSessionTable::query()->setSelect(['ID', 'HOST_DOC_KEY', 'PLATFORM_USER_ID',
			'ACCESS_SOURCE_TYPE', 'ACCESS_SOURCE_ID', 'ACCESS_EXPIRES_AT', 'ACCESS_REVOKE_STATE',
			'ACTOR_USER_ID', 'ACCESS_CONTEXT_HASH', 'ACCESS_CONTEXT'])
			->where('ACCESS_REVOKE_STATE', 0)->whereNull('REVOKE_RETRY_AT')
			->whereIn('HOST_DOC_KEY', $this->documents())
			->setOrder(['ID' => 'ASC'])->setLimit(self::LIMIT);
		$rows = (clone $query)->where('ID', '>', $cursor)->fetchAll();
		if (!$rows && $cursor > 0 && $deadline - hrtime(true) / 1e9 >= 1.1)
		{
			$rows = $query->fetchAll();
		}
		if (!$rows) { return []; }
		$eligible = static fn(array $row): bool => (int)$row['ACCESS_REVOKE_STATE'] === 0
			&& is_string($row['PLATFORM_USER_ID']) && $row['PLATFORM_USER_ID'] !== ''
			&& (!$row['ACCESS_EXPIRES_AT'] instanceof DateTime
				|| $row['ACCESS_EXPIRES_AT']->getTimestamp() > $now->getTimestamp());
		$bindings = array_values(array_filter($rows, $eligible));
		if (!$bindings)
		{
			foreach ($rows as &$row) { $row['sourceValid'] = true; }
			unset($row);
			return $rows;
		}
		// Batch the optimistic checks; only an invalid source enters a document transaction for confirmation.
		$externalBindings = array_values(array_filter($bindings, static fn(array $row): bool =>
			in_array($row['ACCESS_SOURCE_TYPE'], ['external', 'unified'], true)));
		$sources = $externalBindings ? array_column(ExternalLinkTable::query()->setSelect(['ID', 'OBJECT_ID', 'VERSION_ID',
			'ACCESS_RIGHT', 'DEATH_TIME'])->whereIn('ID', array_unique(array_column($externalBindings, 'ACCESS_SOURCE_ID')))
			->fetchAll(), null, 'ID') : [];
		$sourceRevisions = AccessRevocation::sourceRevisions(array_column($externalBindings, 'ACCESS_SOURCE_ID'));
		$documents = array_column(DocumentStateTable::query()->setSelect(['HOST_DOC_KEY', 'OBJECT_ID'])
			->whereIn('HOST_DOC_KEY', array_unique(array_column($bindings, 'HOST_DOC_KEY')))
			->where('EDIT_SCOPE_VERSION', 1)->where('SESSION_TYPE', DocumentSession::TYPE_EDIT)->where('VERSION_ID', 0)
			->fetchAll(), null, 'HOST_DOC_KEY');
		$objects = $documents ? array_column(ObjectTable::query()->setSelect(['*'])
			->whereIn('ID', array_unique(array_column($documents, 'OBJECT_ID')))->fetchAll(), null, 'ID') : [];
		$internalIds = [];
		foreach ($bindings as $binding)
		{
			if (self::internalUnifiedFingerprint($binding) !== null && isset($documents[$binding['HOST_DOC_KEY']]))
			{
				$internalIds[] = (int)$documents[$binding['HOST_DOC_KEY']]['OBJECT_ID'];
			}
		}
		$internalFingerprints = UnifiedLinkSourceRevision::fingerprintsByObjectIds(array_unique($internalIds));
		$files = array_map(static fn(array $object): File => File::buildFromArray($object), $objects);
		$links = array_map(static fn(array $source): ExternalLink => ExternalLink::buildFromArray($source), $sources);
		$sourceMatches = [];
		foreach ($rows as &$row)
		{
			if (!$eligible($row)) { $row['sourceValid'] = true; continue; }
			$source = $sources[$row['ACCESS_SOURCE_ID']] ?? null;
			$objectId = $documents[$row['HOST_DOC_KEY']]['OBJECT_ID'] ?? null;
			$internalFingerprint = self::internalUnifiedFingerprint($row);
			$file = $files[$objectId] ?? null;
			$link = $links[$row['ACCESS_SOURCE_ID']] ?? null;
			if ($link && $file)
			{
				$sourceMatches[$row['ACCESS_SOURCE_ID']][$objectId] ??=
					DocumentStateRepository::externalLinkMatchesFile($link, $file);
			}
			$row['sourceValid'] = $objectId !== null && isset($objects[$objectId])
				&& (int)$objects[$objectId]['DELETED_TYPE'] === 0
				&& ($internalFingerprint === null || ($internalFingerprint === ($internalFingerprints[$objectId] ?? null)
					&& $file && ServiceLocator::getInstance()->get(UnifiedLinkAccessService::class)
						->checkFreshUnifiedLinkSource($file, (int)$row['ACTOR_USER_ID'])->canEdit()))
				&& (!in_array($row['ACCESS_SOURCE_TYPE'], ['external', 'unified'], true) || ($source
					&& ($sourceMatches[$row['ACCESS_SOURCE_ID']][$objectId] ?? false)
					&& (int)$source['VERSION_ID'] === 0
					&& ((int)$source['ACCESS_RIGHT'] === ExternalLinkTable::ACCESS_RIGHT_EDIT
						|| ($row['ACCESS_SOURCE_TYPE'] === 'unified' && (int)$row['ACTOR_USER_ID'] > 0
							&& $file && (new SharedEditingAccess())->hasShortLinkEditAccess(
								$file,
								(int)$row['ACCESS_SOURCE_ID'],
								(int)$row['ACTOR_USER_ID'],
								$internalFingerprint !== null ? 'internal-unified' : 'native',
							)))
					&& isset($sourceRevisions[$row['ACCESS_SOURCE_ID']])
					&& AccessRevocation::hasCurrentSourceRevision($row, $sourceRevisions[$row['ACCESS_SOURCE_ID']])
				&& ($source['DEATH_TIME'] === null || $source['DEATH_TIME']->getTimestamp() > $now->getTimestamp())
				&& $source['DEATH_TIME']?->getTimestamp() === $row['ACCESS_EXPIRES_AT']?->getTimestamp()));
		}
		unset($row);
		return $rows;
	}

	private static function internalUnifiedFingerprint(array $row): ?string
	{
		if (!in_array($row['ACCESS_SOURCE_TYPE'], ['internal', 'unified'], true)) { return null; }
		$access = SharedSessionScope::access($row);
		$value = $access['context']['confirmationFingerprints']['unifiedLink'] ?? null;
		return is_string($value) ? $value : null;
	}

	/** Each walk wraps once; an earlier newly inserted due date cannot be lost behind a saved cursor. */
	private function candidates(string $kind, DateTime $now, float $deadline): array
	{
		$field = $kind === 'expiry' ? 'ACCESS_EXPIRES_AT' : 'REVOKE_RETRY_AT';
		$stored = json_decode(Option::get('disk', self::CURSOR_PREFIX . $kind . '_cursor', ''), true);
		$start = is_array($stored) && count($stored) === 2 && is_int($stored[1] ?? null)
			&& ($stored[0] === null || is_int($stored[0])) ? $stored : null;
		$cursor = $start;
		$wrapped = false;
		$pairs = [];
		while (count($pairs) < self::LIMIT && $deadline - hrtime(true) / 1e9 >= 1.1)
		{
			$query = PlatformSessionTable::query()->setSelect(['ID', 'HOST_DOC_KEY', 'PLATFORM_USER_ID', $field])
				->where('ACCESS_REVOKE_STATE', $kind === 'expiry' ? 0 : 1)
				->whereIn('HOST_DOC_KEY', $this->documents())
				->whereNotNull('PLATFORM_USER_ID')->where('PLATFORM_USER_ID', '!=', '')
				->where($field, '<=', $now)->setOrder([$field => 'ASC', 'ID' => 'ASC'])->setLimit(self::LIMIT);
			if ($cursor !== null)
			{
				$query->where($this->after($field, $cursor));
			}
			if ($wrapped)
			{
				$query->where(Query::filter()->logic('or')
					->where($field, '<', DateTime::createFromTimestamp($start[0]))
					->where(Query::filter()->where($field, DateTime::createFromTimestamp($start[0]))
						->where('ID', '<=', $start[1])));
			}
			$rows = $query->fetchAll();
			foreach ($rows as $row)
			{
				$cursor = [$row[$field]->getTimestamp(), (int)$row['ID']];
				if (preg_match('/^vp1_[a-f0-9]{32}$/D', $row['PLATFORM_USER_ID']) !== 1)
				{
					continue;
				}
				$pairs[$row['HOST_DOC_KEY'] . "\0" . $row['PLATFORM_USER_ID']] ??= $row;
				if (count($pairs) === self::LIMIT)
				{
					break;
				}
			}
			if (count($rows) < self::LIMIT)
			{
				if ($start === null || $wrapped)
				{
					break;
				}
				$wrapped = true;
				$cursor = null;
			}
		}
		return array_values($pairs);
	}

	private function after(string $field, array $cursor): \Bitrix\Main\ORM\Query\Filter\ConditionTree
	{
		$date = DateTime::createFromTimestamp((int)$cursor[0]);
		return Query::filter()->logic('or')->where($field, '>', $date)
			->where(Query::filter()->where($field, $date)->where('ID', '>', $cursor[1]));
	}

	private function documents(): Query
	{
		$query = DocumentStateTable::query()->setSelect(['HOST_DOC_KEY'])->where('EDIT_SCOPE_VERSION', 1)
			->where('SESSION_TYPE', DocumentSession::TYPE_EDIT)->where('VERSION_ID', 0);
		$namespaces = $this->repository->currentNamespaceValues();
		if ($namespaces !== null)
		{
			$query->whereIn('NAMESPACE', $namespaces);
		}
		return $query;
	}

	private function expire(array $candidate, DateTime $now, float $deadline): Result
	{
		return $this->repository->withLockedDocument($candidate['HOST_DOC_KEY'], function (array $state, ?File $lockedFile) use (
			$candidate, $now
		): Result {
			if (!$this->repository->isInCurrentNamespace($state)
				|| SharedSessionScope::version($state) !== 1 || (int)$state['SESSION_TYPE'] !== DocumentSession::TYPE_EDIT
				|| (int)$state['VERSION_ID'] !== 0)
			{
				return new Result();
			}
			$query = PlatformSessionTable::query()->setSelect(SharedEditingSchema::sessionFields())
				->where('HOST_DOC_KEY', $candidate['HOST_DOC_KEY'])
				->where('PLATFORM_USER_ID', $candidate['PLATFORM_USER_ID'])->setOrder(['ID' => 'ASC']);
			// The real file lock serializes source mutations; take source rows before registry rows.
			$sourceIds = [];
			$hasInternalUnified = false;
			foreach ($query->fetchAll() as $row)
			{
				$hasInternalUnified = $hasInternalUnified || self::internalUnifiedFingerprint($row) !== null;
				if (in_array($row['ACCESS_SOURCE_TYPE'], ['external', 'unified'], true))
				{
					$sourceIds[(int)$row['ACCESS_SOURCE_ID']] = true;
				}
			}
			ksort($sourceIds, SORT_NUMERIC);
			$sources = [];
			foreach ($sourceIds as $id => $_)
			{
				$sources[$id] = DocumentStateRepository::fetchForUpdate(ExternalLinkTable::query()
					->setSelect(['ID', 'OBJECT_ID', 'VERSION_ID', 'ACCESS_RIGHT', 'DEATH_TIME'])->where('ID', $id));
			}
			$sourceRevisions = AccessRevocation::sourceRevisions(array_keys($sourceIds), forUpdate: true);
			$internalFingerprint = $hasInternalUnified
				? (UnifiedLinkSourceRevision::fingerprintsByObjectIds([(int)$state['OBJECT_ID']], true)[(int)$state['OBJECT_ID']] ?? null)
				: null;
			$rows = DocumentStateRepository::fetchAllForUpdate($query);
			$expired = !$lockedFile || $lockedFile->isDeleted();
			$pending = false;
			foreach ($rows as $row)
			{
				$pending = $pending || (int)$row['ACCESS_REVOKE_STATE'] === 1;
				if ((int)$row['ACCESS_REVOKE_STATE'] !== 0)
				{
					continue;
				}
				$expectedInternal = self::internalUnifiedFingerprint($row);
				$expired = $expired || ($expectedInternal !== null && ($expectedInternal !== $internalFingerprint
					|| !$lockedFile || !ServiceLocator::getInstance()->get(UnifiedLinkAccessService::class)
						->checkFreshUnifiedLinkSource($lockedFile, (int)$row['ACTOR_USER_ID'])->canEdit()));
				// The original grant expiry remains authoritative even if the source was later extended.
				$expired = $expired || ($row['ACCESS_EXPIRES_AT'] instanceof DateTime
					&& $row['ACCESS_EXPIRES_AT']->getTimestamp() <= $now->getTimestamp());
				if (in_array($row['ACCESS_SOURCE_TYPE'], ['external', 'unified'], true))
				{
					$source = $sources[(int)$row['ACCESS_SOURCE_ID']] ?? null;
					$expired = $expired || !$source || !$lockedFile
						|| !DocumentStateRepository::externalLinkMatchesFile(
							ExternalLink::buildFromArray($source), $lockedFile)
						|| (int)$source['VERSION_ID'] !== 0
						|| ($row['ACCESS_SOURCE_TYPE'] === 'unified' && (int)$row['ACTOR_USER_ID'] > 0
							? (!$lockedFile || !(new SharedEditingAccess())->hasShortLinkEditAccess(
								$lockedFile,
								(int)$row['ACCESS_SOURCE_ID'],
								(int)$row['ACTOR_USER_ID'],
								$expectedInternal !== null ? 'internal-unified' : 'native',
							))
							: (int)$source['ACCESS_RIGHT'] !== ExternalLinkTable::ACCESS_RIGHT_EDIT)
						|| !isset($sourceRevisions[$row['ACCESS_SOURCE_ID']])
						|| !AccessRevocation::hasCurrentSourceRevision($row, $sourceRevisions[$row['ACCESS_SOURCE_ID']])
						|| ($source['DEATH_TIME'] instanceof DateTime && $source['DEATH_TIME']->getTimestamp() <= $now->getTimestamp())
						|| ($source['DEATH_TIME']?->getTimestamp() !== $row['ACCESS_EXPIRES_AT']?->getTimestamp());
				}
			}
			if (!$expired)
			{
				return new Result();
			}
			$registry = new SessionRegistry($this->repository);
			foreach ($rows as $row)
			{
				if ((int)$row['ACCESS_REVOKE_STATE'] !== 0)
				{
					continue;
				}
				$result = $registry->markRevokeLocked($row);
				if (!$result->isSuccess())
				{
					return $result;
				}
			}
			return (new Result())->setData(['marked' => !$pending]);
		}, deadline: $deadline);
	}

	private function origin(string $key, string $subject, int $confirmedAt): ?int
	{
		$fields = ['ACCESS_EXPIRES_AT'];
		if (SharedEditingSchema::hasRevokeTelemetry())
		{
			$fields[] = 'REVOKE_REQUESTED_AT';
		}
		$origin = null;
		foreach (PlatformSessionTable::query()->setSelect($fields)->where('HOST_DOC_KEY', $key)
			->where('PLATFORM_USER_ID', $subject)->fetchAll() as $row)
		{
			foreach ($row as $date)
			{
				if ($date instanceof DateTime && $date->getTimestamp() <= $confirmedAt)
				{
					$origin = min($origin ?? PHP_INT_MAX, $date->getTimestamp());
				}
			}
		}
		return $origin;
	}

	/** Presence uses index prefixes, without grouping history or filtering a sparse shared tail. */
	private function queuePresence(DateTime $now, float $deadline): ?array
	{
		$presence = null;
		SqlDeadlineScope::run($deadline, static function () use ($now, $deadline, &$presence): Result {
			if (!SharedEditingSchema::isReady()) { return new Result(); }
			$retry = (bool)PlatformSessionTable::query()->setSelect(['ID'])->where('ACCESS_REVOKE_STATE', 1)->setLimit(1)->fetch();
			if ($deadline - hrtime(true) / 1e9 < 1.1) { return new Result(); }
			$expiry = (bool)PlatformSessionTable::query()->setSelect(['ID'])->where('ACCESS_REVOKE_STATE', 0)
				->where('ACCESS_EXPIRES_AT', '<=', $now)->setLimit(1)->fetch();
			$presence = ['retry' => $retry, 'expiry' => $expiry];
			return new Result();
		});
		return $presence;
	}

	/** Limit physical rows before checking documents or distinct subjects; partial counts remain lower bounds. */
	private function backlog(DateTime $now, float $deadline): array
	{
		$pairs = [];
		$complete = false;
		SqlDeadlineScope::run($deadline, function () use ($now, $deadline, &$pairs, &$complete): Result {
			if (!SharedEditingSchema::isReady()) { return new Result(); }
			$samples = [];
			$exhaustive = true;
			foreach (['retry', 'expiry'] as $kind)
			{
				if ($deadline - hrtime(true) / 1e9 < 1.1) { return new Result(); }
				$rows = $this->metricSample($kind, $now);
				$exhaustive = $exhaustive && count($rows) <= self::METRIC_ROW_LIMIT;
				array_push($samples, ...array_slice($rows, 0, self::METRIC_ROW_LIMIT));
			}
			if ($deadline - hrtime(true) / 1e9 < 1.1) { return new Result(); }
			$keys = array_values(array_unique(array_column($samples, 'HOST_DOC_KEY')));
			$documents = $keys ? array_fill_keys(array_column(DocumentStateTable::query()->setSelect(['HOST_DOC_KEY'])
				->whereIn('HOST_DOC_KEY', $keys)->where('EDIT_SCOPE_VERSION', 1)
				->where('SESSION_TYPE', DocumentSession::TYPE_EDIT)->where('VERSION_ID', 0)
				->setLimit(2 * self::METRIC_ROW_LIMIT)->fetchAll(), 'HOST_DOC_KEY'), true) : [];
			$wall = time();
			foreach ($samples as $row)
			{
				if (!isset($documents[$row['HOST_DOC_KEY']]) || !is_string($row['PLATFORM_USER_ID']) || $row['PLATFORM_USER_ID'] === '') { continue; }
				$key = $row['HOST_DOC_KEY'] . "\0" . $row['PLATFORM_USER_ID'];
				$pair = $pairs[$key] ?? ['pending' => false, 'expiry' => false, 'retry' => false, 'unknown' => false, 'origin' => null];
				$pending = (int)$row['ACCESS_REVOKE_STATE'] === 1;
				$pair['pending'] = $pair['pending'] || $pending;
				$pair['expiry'] = $pair['expiry'] || !$pending;
				$retry = $row['REVOKE_RETRY_AT'];
				$pair['retry'] = $pair['retry'] || ($pending && (!$retry instanceof DateTime || $retry->getTimestamp() <= $now->getTimestamp()));
				$date = $pending ? ($row['REVOKE_REQUESTED_AT'] ?? null) : $row['ACCESS_EXPIRES_AT'];
				$known = $date instanceof DateTime && (!$pending || $date->getTimestamp() <= $wall);
				$pair['unknown'] = $pair['unknown'] || ($pending && !$known);
				if ($known) { $pair['origin'] = min($pair['origin'] ?? PHP_INT_MAX, $date->getTimestamp()); }
				$pairs[$key] = $pair;
			}
			$complete = $exhaustive && $deadline - hrtime(true) / 1e9 >= 1.1;
			return new Result();
		});
		return $this->backlogMetrics($pairs, $complete, $now);
	}

	private function metricSample(string $kind, DateTime $now): array
	{
		if (!in_array($kind, ['retry', 'expiry'], true)) { throw new \LogicException('Invalid metric queue.'); }
		$fields = ['ID', 'HOST_DOC_KEY', 'PLATFORM_USER_ID', 'ACCESS_REVOKE_STATE', 'REVOKE_RETRY_AT', 'ACCESS_EXPIRES_AT'];
		if (SharedEditingSchema::hasRevokeTelemetry()) { $fields[] = 'REVOKE_REQUESTED_AT'; }
		$field = $kind === 'retry' ? 'REVOKE_RETRY_AT' : 'ACCESS_EXPIRES_AT';
		$c = Application::getConnection();
		if ($c->getType() !== 'pgsql')
		{
			$query = PlatformSessionTable::query()->setSelect($fields)->where('ACCESS_REVOKE_STATE', $kind === 'retry' ? 1 : 0)
				->setOrder([$field => 'ASC', 'ID' => 'ASC'])->setLimit(self::METRIC_ROW_LIMIT + 1);
			if ($kind === 'expiry') { $query->where($field, '<=', $now); }
			return $query->fetchAll();
		}
		$helper = $c->getSqlHelper();
		$quote = static fn(string $name): string => $helper->quote(strtolower($name));
		$table = $quote(PlatformSessionTable::getTableName());
		$sql = 'SELECT ' . $quote('ID') . ' FROM ' . $table
			. ' WHERE ' . $quote('ACCESS_REVOKE_STATE') . '=' . ($kind === 'retry' ? 1 : 0);
		$order = $quote($field) . ' ASC NULLS LAST,' . $quote('ID') . ' ASC';
		if ($kind === 'expiry')
		{
			$tuple = '(' . $quote('ACCESS_REVOKE_STATE') . ',' . $quote($field) . ',' . $quote('ID') . ')';
			$sql = 'SELECT ' . $quote('ID') . ' FROM ' . $table . ' WHERE ' . $tuple . ">=(0,'-infinity'::timestamp,0)"
				. ' AND ' . $tuple . '<=(0,' . (new SqlExpression('?', $now))->compile() . ',' . PHP_INT_MAX . ')';
			$order = $quote('ACCESS_REVOKE_STATE') . ' ASC,' . $order;
		}
		// Limit the covering index page before fetching wide registry fields; LATERAL keeps each lookup on its primary key.
		$page = $sql . ' ORDER BY ' . $order . ' LIMIT ' . (self::METRIC_ROW_LIMIT + 1);
		$projection = implode(',', array_map(static fn(string $name): string => 'metric_row.' . $quote($name), $fields));
		$rows = $c->query('SELECT metric_values.* FROM (' . $page . ') metric_page CROSS JOIN LATERAL'
			. ' (SELECT ' . $projection . ' FROM ' . $table . ' metric_row WHERE metric_row.' . $quote('ID')
			. '=metric_page.' . $quote('ID') . ' LIMIT 1) metric_values')->fetchAll();
		foreach ($rows as &$row)
		{
			$row = array_change_key_case($row, CASE_UPPER);
			foreach (['REVOKE_RETRY_AT', 'ACCESS_EXPIRES_AT', 'REVOKE_REQUESTED_AT'] as $date)
			{
				if (isset($row[$date]) && !$row[$date] instanceof DateTime) { $row[$date] = $helper->convertFromDbDateTime($row[$date]); }
			}
		}
		unset($row);
		return $rows;
	}

	private function backlogMetrics(array $pairs, bool $complete, DateTime $now): array
	{
		$counts = ['pendingCount' => 0, 'dueExpiryCount' => 0, 'dueRetryCount' => 0, 'unknownAgeCount' => 0];
		$origin = null;
		foreach ($pairs as $pair)
		{
			foreach (['pendingCount' => 'pending', 'dueExpiryCount' => 'expiry', 'dueRetryCount' => 'retry', 'unknownAgeCount' => 'unknown'] as $name => $field)
			{
				$counts[$name] += (int)$pair[$field];
			}
			if ($pair['origin'] !== null) { $origin = min($origin ?? PHP_INT_MAX, $pair['origin']); }
		}
		$data = ['metricsComplete' => $complete, 'countsAreLowerBounds' => !$complete];
		foreach ($counts as $name => $count)
		{
			$data[$name] = $complete ? $count : null;
			$data[$name . 'LowerBound'] = $count;
		}
		$data['oldestDueAgeLowerBound'] = $origin === null ? 0 : max(0, max(time(), $now->getTimestamp()) - $origin);
		$data['oldestDueAge'] = $complete && ($origin !== null || $counts['unknownAgeCount'] === 0)
			? $data['oldestDueAgeLowerBound'] : null;
		return $data;
	}
}
