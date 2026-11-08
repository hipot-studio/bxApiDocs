<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\LegalRegistry;

use Bitrix\Crm\V2\Internal\Model\LegalRegistry\SourceTable;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\UsageTable;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\VersionTable;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\SubmissionTable;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\FactTable;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\EventTable;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\RegistryResult;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\SubmissionManifest;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\SubmissionResolution;
use Bitrix\Main\Application;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\Type\DateTime;

/** Internal storage only. Policy, presentation proof and CRM receipt are verified by callers. */
final class LedgerRepository
{
	public function prepareUnique(SubmissionManifest $manifest): RegistryResult
	{
		return $this->prepare($manifest, true);
	}

	private function prepare(SubmissionManifest $manifest, bool $retryDuplicate): RegistryResult
	{
		try
		{
			$identity = self::manifestIdentity($manifest);
			$hash = hash('sha256', self::canonicalJson($identity));
			$snapshot = self::canonicalJson($identity + ['presentedAt' => $manifest->presentedAt->format('Y-m-d\TH:i:s.uP')]);
			if (strlen($manifest->submissionKey) > 255 || strlen($snapshot) > 1048576 || count($manifest->items) > 100) { return self::error('INVALID_PAYLOAD'); }
		}
		catch (\Throwable) { return self::error('INVALID_PAYLOAD'); }
		$duplicate = false;
		$result = $this->transaction(function () use ($manifest, $hash, $snapshot, &$duplicate): RegistryResult {
			$source = self::currentRows(SourceTable::class, ['=FORM_ID' => $manifest->formId])[0] ?? false;
			if (!$source || !self::currentRow(SourceTable::class, (int)$source['ID'])) { return self::error('NOT_FOUND_OR_DENIED'); }
			$source = self::currentRow(SourceTable::class, (int)$source['ID']);
			$existing = self::currentRows(SubmissionTable::class, ['=SOURCE_ID' => (int)$source['ID'], '=SUBMISSION_KEY' => $manifest->submissionKey])[0] ?? false;
			if ($existing)
			{
				if (!self::currentRow(SubmissionTable::class, (int)$existing['ID'])) { return self::error('STORAGE_ERROR'); }
				$existing = self::currentRow(SubmissionTable::class, (int)$existing['ID']);

				return hash_equals($existing['PAYLOAD_HASH'], $hash) ? $this->submissionResult($existing, true) : self::error('IDEMPOTENCY_CONFLICT');
			}
			if ($source['STATE'] !== 'active' || (int)$source['REVISION'] !== $manifest->sourceRevision) { return self::error('STALE_PRESENTATION'); }
			$items = [];
			foreach ($manifest->items as $item)
			{
				if (strlen($item->effectiveText) > 262144 || strlen($item->effectiveLabel) > 262144) { return self::error('INVALID_PAYLOAD'); }
				$items[$item->documentId] = $item;
				$binding = self::currentRows(UsageTable::class, ['=SOURCE_ID' => (int)$source['ID'], '=DOCUMENT_ID' => $item->documentId, '=DATE_END' => null])[0] ?? false;
				if (!$binding || (int)$binding['VERSION_ID'] !== $item->versionId || (int)$binding['REVISION'] !== $item->bindingRevision) { return self::error('STALE_PRESENTATION'); }
				if ($binding['REQUIRED'] === 'Y' && $item->answer !== 'accepted') { return self::error('INVALID_PAYLOAD'); }
			}
			foreach (self::currentRows(UsageTable::class, ['=SOURCE_ID' => (int)$source['ID'], '=DATE_END' => null, '=REQUIRED' => 'Y']) as $binding)
			{
				if (!isset($items[(int)$binding['DOCUMENT_ID']])) { return self::error('INVALID_PAYLOAD'); }
			}
			try
			{
				$saved = SubmissionTable::add(['SOURCE_ID' => (int)$source['ID'], 'SUBMISSION_KEY' => $manifest->submissionKey,
					'PAYLOAD_HASH' => $hash, 'MANIFEST' => $snapshot, 'SERVER_RECEIVED_AT' => new DateTime(), 'STATE' => 'pending']);
			}
			catch (DuplicateEntryException) { $duplicate = true;

return self::error('STORAGE_ERROR'); }
			if (!$saved->isSuccess()) { return self::error('STORAGE_ERROR'); }

			return (new RegistryResult())->setSubmissionId((int)$saved->getId())->setState('pending');
		});

		// A duplicate statement aborts a PostgreSQL transaction: inspect only after rollback.
		return $duplicate && $retryDuplicate ? $this->prepare($manifest, false) : $result;
	}

	public function readSubmission(int $id): ?array
	{

		try
		{
			$row = SubmissionTable::getById($id)->fetch();

			return $row ?: null;
		}
		catch (\Throwable) { throw new \RuntimeException('STORAGE_ERROR'); }
	}

	/** @param list<array> $facts Frozen content, rule, timezone and computed expiry. */
	public function commitBatch(int $submissionId, SubmissionResolution $resolution, array $facts): RegistryResult
	{
		return $this->transaction(function () use ($submissionId, $resolution, $facts): RegistryResult {
			$initial = SubmissionTable::getById($submissionId)->fetch();
			if (!$initial || !self::currentRow(SourceTable::class, (int)$initial['SOURCE_ID'])
				|| !self::currentRow(SubmissionTable::class, $submissionId)) { return self::error('NOT_FOUND_OR_DENIED'); }
			$submission = self::currentRow(SubmissionTable::class, $submissionId);
			if ((int)$submission['SOURCE_ID'] !== (int)$initial['SOURCE_ID']) { return self::error('INVALID_STATE'); }
			if ($submission['STATE'] === 'committed') { return $this->submissionResult($submission, true); }
			if ($submission['STATE'] !== 'pending') { return self::error('INVALID_STATE'); }
			if (!array_is_list($facts) || count($facts) > 100) { return self::error('INVALID_PAYLOAD'); }
			$manifest = json_decode($submission['MANIFEST'], true, 512, JSON_THROW_ON_ERROR);
			$accepted = [];
			foreach ($manifest['items'] as $item) { if ($item['answer'] === 'accepted') { $accepted[$item['documentId']] = $item; } }
			if (count($accepted) !== count($facts)) { return self::error('INVALID_PAYLOAD'); }
			$seen = [];
			foreach ($facts as $fact)
			{
				if (!is_array($fact) || !is_int($fact['DOCUMENT_ID'] ?? null)) { return self::error('INVALID_PAYLOAD'); }
				$item = $accepted[$fact['DOCUMENT_ID']] ?? null;
				if (!$item || isset($seen[$fact['DOCUMENT_ID']])) { return self::error('INVALID_PAYLOAD'); }
				$seen[$fact['DOCUMENT_ID']] = true;
				foreach (['VERSION_ID' => 'versionId', 'EFFECTIVE_TEXT' => 'effectiveText', 'EFFECTIVE_LABEL' => 'effectiveLabel', 'TEXT_HASH' => 'textHash'] as $field => $key)
				{
					if (($fact[$field] ?? null) !== $item[$key]) { return self::error('INVALID_PAYLOAD'); }
				}
				if (!is_string($fact['SUBSTITUTIONS'] ?? null)
					|| self::canonicalJson(json_decode($fact['SUBSTITUTIONS'], false, 512, JSON_THROW_ON_ERROR)) !== self::canonicalJson((object)$item['substitutions'])) { return self::error('INVALID_PAYLOAD'); }
				$version = VersionTable::getById($item['versionId'])->fetch();
				if (!$version || !is_string($fact['RULE_SNAPSHOT'] ?? null)
					|| self::canonicalJson(json_decode($version['EXPIRY_RULE'], false, 512, JSON_THROW_ON_ERROR)) !== self::canonicalJson(json_decode($fact['RULE_SNAPSHOT'], false, 512, JSON_THROW_ON_ERROR))) { return self::error('INVALID_PAYLOAD'); }
				if (!is_string($fact['TIMEZONE'] ?? null) || strlen($fact['TIMEZONE']) > 64) { return self::error('INVALID_PAYLOAD'); }
				try { new \DateTimeZone($fact['TIMEZONE']); }
				catch (\Throwable) { return self::error('INVALID_PAYLOAD'); }
				if (($fact['EXPIRES_AT'] ?? null) !== null && !$fact['EXPIRES_AT'] instanceof DateTime) { return self::error('INVALID_PAYLOAD'); }
			}
			if ($resolution->personBindings === []) { return self::error('SUBJECT_UNRESOLVED'); }
			try { $subjectId = (new SubjectRepository())->resolveOrCreate($resolution->originalSubject, $resolution->personBindings); }
			catch (\UnexpectedValueException) { return self::error('SUBJECT_UNRESOLVED'); }
			$ids = [];
			foreach ($facts as $fact)
			{
				$data = array_intersect_key($fact, array_flip(['DOCUMENT_ID', 'VERSION_ID', 'EFFECTIVE_TEXT', 'EFFECTIVE_LABEL', 'SUBSTITUTIONS', 'TEXT_HASH', 'RULE_SNAPSHOT', 'TIMEZONE', 'EXPIRES_AT']));
				$data += ['SUBMISSION_ID' => $submissionId, 'SUBJECT_ID' => $subjectId,
					'ORIGINAL_SUBJECT' => self::canonicalJson($resolution->originalSubject), 'ACCEPTED_AT' => $submission['SERVER_RECEIVED_AT']];
				$saved = FactTable::add($data);
				if (!$saved->isSuccess()) { return self::error('STORAGE_ERROR'); }
				$ids[] = (int)$saved->getId();
			}
			$saved = SubmissionTable::update($submissionId, ['STATE' => 'committed', 'RESOLUTION_SNAPSHOT' => self::canonicalJson($resolution),
				'RESULT_ID' => $resolution->resultId, 'ACTIVITY_ID' => $resolution->activityId]);
			if (!$saved->isSuccess()) { return self::error('STORAGE_ERROR'); }

			return (new RegistryResult())->setSubmissionId($submissionId)->setState('committed')->setFactIds($ids);
		});
	}

	public function appendEvent(string $key, array $data): RegistryResult
	{
		return $this->append($key, $data, true);
	}

	private function append(string $key, array $data, bool $retryDuplicate): RegistryResult
	{
		if ($key === '' || strlen($key) > 255) { return self::error('INVALID_PAYLOAD'); }
		$duplicate = false;
		$result = $this->transaction(function () use ($key, $data, &$duplicate): RegistryResult {
			$factId = $data['FACT_ID'] ?? null;
			if ($factId !== null)
			{
				$fact = FactTable::getById($factId)->fetch();
				if (!$fact) { return self::error('NOT_FOUND_OR_DENIED'); }
				$submission = SubmissionTable::getById((int)$fact['SUBMISSION_ID'])->fetch();
				if (!$submission || !self::currentRow(SourceTable::class, (int)$submission['SOURCE_ID'])
					|| !self::currentRow(SubmissionTable::class, (int)$submission['ID'])
					|| !self::currentRow(\Bitrix\Crm\V2\Internal\Model\LegalRegistry\SubjectTable::class, (int)$fact['SUBJECT_ID'])) { return self::error('NOT_FOUND_OR_DENIED'); }
			}
			if ($factId === null && isset($data['SUBJECT_ID']) && (!is_int($data['SUBJECT_ID']) || !self::currentRow(\Bitrix\Crm\V2\Internal\Model\LegalRegistry\SubjectTable::class, $data['SUBJECT_ID']))) { return self::error('NOT_FOUND_OR_DENIED'); }
			$existing = self::currentRows(EventTable::class, ['=EVENT_KEY' => $key])[0] ?? false;
			if ($existing)
			{
				if ($existing['KIND'] === 'revoke') { return self::error('ALREADY_REVOKED')->setFactIds([(int)$existing['FACT_ID']])->setReplay(true); }

				return ($existing['KIND'] === ($data['KIND'] ?? null) && $existing['PAYLOAD'] === ($data['PAYLOAD'] ?? null))
					? (new RegistryResult())->setReplay(true) : self::error('IDEMPOTENCY_CONFLICT');
			}
			try { $saved = EventTable::add(array_replace($data, ['EVENT_KEY' => $key])); }
			catch (DuplicateEntryException) { $duplicate = true;

return self::error('STORAGE_ERROR'); }

			return $saved->isSuccess() ? new RegistryResult() : self::error('STORAGE_ERROR');
		});

		return $duplicate && $retryDuplicate ? $this->append($key, $data, false) : $result;
	}

	private function submissionResult(array $row, bool $replay): RegistryResult
	{
		$ids = array_map(static fn(array $fact): int => (int)$fact['ID'], self::currentRows(FactTable::class, ['=SUBMISSION_ID' => (int)$row['ID']]));

		return (new RegistryResult())->setSubmissionId((int)$row['ID'])->setState($row['STATE'])->setFactIds($ids)->setReplay($replay);
	}

	private static function manifestIdentity(SubmissionManifest $manifest): array
	{
		$items = [];
		foreach ($manifest->items as $item)
		{
			$values = get_object_vars($item); $values['substitutions'] = (object)$item->substitutions; $items[] = $values;
		}
		usort($items, static fn(array $a, array $b): int => $a['documentId'] <=> $b['documentId']);

		return ['submissionKeyBase64' => base64_encode($manifest->submissionKey), 'formId' => $manifest->formId,
			'sourceRevision' => $manifest->sourceRevision, 'items' => $items];
	}

	public static function canonicalJson(mixed $value): string
	{
		return json_encode(self::canonicalValue($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
	}

	private static function canonicalValue(mixed $value): mixed
	{
		if (is_object($value))
		{
			$values = get_object_vars($value); ksort($values, SORT_STRING);
			foreach ($values as $key => $item) { $values[$key] = self::canonicalValue($item); }

			return (object)$values;
		}
		if (is_array($value))
		{
			if (!array_is_list($value)) { ksort($value, SORT_STRING); }
			foreach ($value as $key => $item) { $value[$key] = self::canonicalValue($item); }
		}

		return $value;
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
