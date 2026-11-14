<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\LegalRegistry;

use Bitrix\Crm\V2\Internal\Model\LegalRegistry\FormSubmissionReceiptTable;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\SubmissionTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\Type\DateTime;

/** Internal CRM receipt storage. The callback writes the CRM result inside the receipt transaction. */
final class FormSubmissionReceiptRepository
{
	/**
	 * @param callable(): array{resultId: ?int, outcome: array} $writeResult
	 * @return array{submissionId: int, resultId: ?int, outcome: array, replay: bool}
	 */
	public function commit(int $submissionId, string $requestHash, callable $writeResult): array
	{
		return $this->commitUnique($submissionId, $requestHash, $writeResult, true);
	}

	private function commitUnique(
		int $submissionId,
		string $requestHash,
		callable $writeResult,
		bool $retryDuplicate,
	): array
	{
		self::validateIdentity($submissionId, $requestHash);
		$duplicate = false;
		try
		{
			return $this->transaction(function () use (
				$submissionId, $requestHash, $writeResult, &$duplicate, $retryDuplicate,
			): array {
				self::lockSubmission($submissionId);
				$row = self::existingRow($submissionId, !$retryDuplicate);
				if ($row === null)
				{
					try
					{
						$saved = FormSubmissionReceiptTable::add([
							'SUBMISSION_ID' => $submissionId,
							'REQUEST_HASH' => $requestHash,
							'STATE' => 'pending',
							'ATTEMPT' => 0,
						]);
					}
					catch (DuplicateEntryException)
					{
						$duplicate = true;
						throw new \RuntimeException('RECEIPT_DUPLICATE');
					}
					if (!$saved->isSuccess()) { throw new \RuntimeException('STORAGE_ERROR'); }
					$row = self::lockedRow($submissionId);
				}
				if ($row === null) { throw new \RuntimeException('STORAGE_ERROR'); }
				self::assertHash($row, $requestHash);
				if ($row['STATE'] === 'committed') { return self::map($row, true); }

				$data = $writeResult();
				$resultId = $data['resultId'] ?? null;
				$outcome = $data['outcome'] ?? null;
				if (($resultId !== null && (!is_int($resultId) || $resultId <= 0)) || !is_array($outcome))
				{
					throw new \InvalidArgumentException('INVALID_CRM_OUTCOME');
				}
				$snapshot = json_encode($outcome, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
				if (strlen($snapshot) > 1048576) { throw new \InvalidArgumentException('INVALID_CRM_OUTCOME'); }
				$saved = FormSubmissionReceiptTable::update((int)$row['ID'], [
					'RESULT_ID' => $resultId,
					'OUTCOME_SNAPSHOT' => $snapshot,
					'STATE' => 'committed',
					'ATTEMPT' => (int)$row['ATTEMPT'] + 1,
					'NEXT_RETRY_AT' => null,
					'LAST_ERROR' => null,
				]);
				if (!$saved->isSuccess()) { throw new \RuntimeException('STORAGE_ERROR'); }

				return ['submissionId' => $submissionId, 'resultId' => $resultId, 'outcome' => $outcome, 'replay' => false];
			});
		}
		catch (\RuntimeException $exception)
		{
			if ($duplicate && $retryDuplicate && $exception->getMessage() === 'RECEIPT_DUPLICATE')
			{
				return $this->commitUnique($submissionId, $requestHash, $writeResult, false);
			}
			throw $exception;
		}
	}

	/** @return ?array{submissionId: int, resultId: ?int, outcome: array, replay: bool} */
	public function find(int $submissionId, string $requestHash): ?array
	{
		self::validateIdentity($submissionId, $requestHash);
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			$row = FormSubmissionReceiptTable::query()->setSelect(['*'])->where('SUBMISSION_ID', $submissionId)
				->setLimit(1)->fetch();
		}
		finally { $pool->useMasterOnly(false); }
		if (!$row) { return null; }
		self::assertHash($row, $requestHash);
		return $row['STATE'] === 'committed' ? self::map($row, true) : null;
	}

	public function recordFailure(int $submissionId, string $requestHash, string $error, ?DateTime $nextRetryAt): void
	{
		$this->recordFailureUnique($submissionId, $requestHash, $error, $nextRetryAt, true);
	}

	private function recordFailureUnique(
		int $submissionId,
		string $requestHash,
		string $error,
		?DateTime $nextRetryAt,
		bool $retryDuplicate,
	): void
	{
		self::validateIdentity($submissionId, $requestHash);
		if (preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $error) !== 1)
		{
			throw new \InvalidArgumentException('INVALID_ERROR');
		}
		$duplicate = false;
		try
		{
			$this->transaction(function () use (
				$submissionId, $requestHash, $error, $nextRetryAt, $retryDuplicate, &$duplicate,
			): void {
				self::lockSubmission($submissionId);
				$row = self::existingRow($submissionId, !$retryDuplicate);
				if ($row === null)
				{
					try
					{
						$saved = FormSubmissionReceiptTable::add([
							'SUBMISSION_ID' => $submissionId,
							'REQUEST_HASH' => $requestHash,
							'STATE' => 'failed',
							'ATTEMPT' => 1,
							'NEXT_RETRY_AT' => $nextRetryAt,
							'LAST_ERROR' => $error,
						]);
					}
					catch (DuplicateEntryException)
					{
						$duplicate = true;
						throw new \RuntimeException('RECEIPT_DUPLICATE');
					}
					if (!$saved->isSuccess()) { throw new \RuntimeException('STORAGE_ERROR'); }
					return;
				}
				self::assertHash($row, $requestHash);
				if ($row['STATE'] === 'committed') { throw new \UnexpectedValueException('INVALID_STATE'); }
				$saved = FormSubmissionReceiptTable::update((int)$row['ID'], [
					'STATE' => 'failed',
					'ATTEMPT' => (int)$row['ATTEMPT'] + 1,
					'NEXT_RETRY_AT' => $nextRetryAt,
					'LAST_ERROR' => $error,
				]);
				if (!$saved->isSuccess()) { throw new \RuntimeException('STORAGE_ERROR'); }
			});
		}
		catch (\RuntimeException $exception)
		{
			if ($duplicate && $retryDuplicate && $exception->getMessage() === 'RECEIPT_DUPLICATE')
			{
				$this->recordFailureUnique($submissionId, $requestHash, $error, $nextRetryAt, false);
				return;
			}
			throw $exception;
		}
	}

	private static function validateIdentity(int $submissionId, string $requestHash): void
	{
		if ($submissionId <= 0 || $submissionId > 2147483647 || preg_match('/^[a-f0-9]{64}$/D', $requestHash) !== 1)
		{
			throw new \InvalidArgumentException('INVALID_PAYLOAD');
		}
	}

	private static function assertHash(array $row, string $requestHash): void
	{
		if (!hash_equals($row['REQUEST_HASH'], $requestHash))
		{
			throw new \UnexpectedValueException('IDEMPOTENCY_CONFLICT');
		}
	}

	private static function map(array $row, bool $replay): array
	{
		return [
			'submissionId' => (int)$row['SUBMISSION_ID'],
			'resultId' => $row['RESULT_ID'] === null ? null : (int)$row['RESULT_ID'],
			'outcome' => json_decode($row['OUTCOME_SNAPSHOT'], true, 512, JSON_THROW_ON_ERROR),
			'replay' => $replay,
		];
	}

	private static function lockedRow(int $submissionId): ?array
	{
		$query = new class(FormSubmissionReceiptTable::getEntity()) extends \Bitrix\Main\ORM\Query\Query {
			protected function query($sql)
			{
				$result = $this->entity->getConnection()->query($sql . ' FOR UPDATE');
				$result->setReplacedAliases($this->replaced_aliases);
				if ($this->isFetchModificationRequired())
				{
					$result->addFetchDataModifier([$this, 'fetchDataModificationCallback']);
				}
				return $result;
			}
		};
		return $query->setSelect(['*'])->where('SUBMISSION_ID', $submissionId)->setLimit(1)->fetch() ?: null;
	}

	private static function existingRow(int $submissionId, bool $forceLock): ?array
	{
		if ($forceLock) { return self::lockedRow($submissionId); }
		$initial = FormSubmissionReceiptTable::query()->setSelect(['ID'])
			->where('SUBMISSION_ID', $submissionId)->setLimit(1)->fetch();
		return $initial ? self::lockedRow($submissionId) : null;
	}

	private static function lockSubmission(int $submissionId): void
	{
		$query = new class(SubmissionTable::getEntity()) extends \Bitrix\Main\ORM\Query\Query {
			protected function query($sql)
			{
				return $this->entity->getConnection()->query($sql . ' FOR UPDATE');
			}
		};
		if (!$query->setSelect(['ID'])->where('ID', $submissionId)->setLimit(1)->fetch())
		{
			throw new \UnexpectedValueException('SUBMISSION_NOT_FOUND');
		}
	}

	private function transaction(callable $callback): mixed
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
			$connection->commitTransaction();
			$started = false;
			return $result;
		}
		catch (\Throwable $exception)
		{
			if ($started)
			{
				try { $connection->rollbackTransaction(); }
				catch (\Bitrix\Main\DB\TransactionException $rollbackError)
				{
					if ($rollbackError->getMessage() !== 'Nested rollbacks are unsupported.')
					{
						throw $rollbackError;
					}
				}
			}
			throw $exception;
		}
		finally { $pool->useMasterOnly(false); }
	}
}
