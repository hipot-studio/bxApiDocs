<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\HtmlEditor;

use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\Audit\AuditTransaction;
use Bitrix\Disk\Internal\Service\Audit\AuditTransactionOutcome;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Main\Application;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\Result;

final class AiLockService
{
	public const ERROR_LOCKED = 'html_ai_locked';
	public const ERROR_UNAVAILABLE = 'html_ai_lock_unavailable';
	public const ERROR_INVALID_OPERATION = 'html_ai_invalid_operation';
	public const EVENT_SET = 'onHtmlAiLockSet';
	public const EVENT_REMOVED = 'onHtmlAiLockRemoved';

	private readonly PersistentStorageInterface $storage;
	private readonly SessionService $sessions;
	private readonly \Closure $clock;

	public function __construct(
		?PersistentStorageInterface $storage = null,
		?SessionService $sessions = null,
		?callable $clock = null,
	)
	{
		$this->storage = $storage ?? ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
		$this->sessions = $sessions ?? new SessionService($clock);
		$this->clock = $clock !== null ? $clock(...) : static fn(): int => time();
	}

	public static function isValidOperationId(string $operationId): bool
	{
		return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $operationId) === 1;
	}

	public function getSnapshot(int $realFileId): ?array
	{
		$lock = $this->read($realFileId);

		return $lock === null ? null : $this->toSnapshot($lock);
	}

	public function lockForOperation(int $fileId, int $userId, int $chatId, string $operationId): Result
	{
		if ($userId <= 0 || $chatId <= 0 || !self::isValidOperationId($operationId))
		{
			return $this->error(self::ERROR_INVALID_OPERATION);
		}

		return $this->mutate($fileId, function (int $realFileId, AuditTransaction $transaction) use (
			$userId,
			$chatId,
			$operationId,
		): Result {
			$current = $this->read($realFileId);
			if ($current !== null)
			{
				return $current['operationId'] === $operationId
					&& $current['chatId'] === $chatId
					&& $current['userId'] === $userId
					? $this->unchanged($current)
					: $this->error(self::ERROR_LOCKED);
			}

			$renewed = $this->sessions->renewForAiTransfer($realFileId, $userId);
			if (!$renewed->isSuccess())
			{
				return $renewed;
			}
			$lock = [
				'operationId' => $operationId,
				'chatId' => $chatId,
				'userId' => $userId,
				'expiresAt' => $renewed->getData()['expiresAt'],
			];
			if (!$this->storage->set($this->key($realFileId), $lock, $lock['expiresAt'] - ($this->clock)()))
			{
				return $this->error(self::ERROR_UNAVAILABLE);
			}
			$this->publishAfterCommit($transaction, self::EVENT_SET, $realFileId, $lock);

			return (new Result())->setData(['changed' => true, 'aiLock' => $this->toSnapshot($lock)]);
		});
	}

	public function removeMatching(int $fileId, int $chatId, string $operationId): Result
	{
		if ($chatId <= 0 || !self::isValidOperationId($operationId))
		{
			return $this->error(self::ERROR_INVALID_OPERATION);
		}

		return $this->mutate($fileId, function (int $realFileId, AuditTransaction $transaction) use (
			$chatId,
			$operationId,
		): Result {
			$current = $this->read($realFileId);
			if ($current === null || $current['chatId'] !== $chatId || $current['operationId'] !== $operationId)
			{
				return $this->unchanged($current);
			}
			if (!$this->storage->delete($this->key($realFileId)))
			{
				return $this->error(self::ERROR_UNAVAILABLE);
			}
			$this->sessions->renewForAiTransfer($realFileId, $current['userId']);
			$this->publishAfterCommit($transaction, self::EVENT_REMOVED, $realFileId, $current);

			return (new Result())->setData(['changed' => true, 'aiLock' => null]);
		});
	}

	private function mutate(int $fileId, callable $mutation): Result
	{
		$connection = Application::getConnection();
		$transaction = new AuditTransaction($connection);
		if (!$transaction->canStartRootTransaction())
		{
			return $this->error(self::ERROR_UNAVAILABLE);
		}
		$file = $fileId > 0 ? File::loadById($fileId) : null;
		if (!$file)
		{
			return $this->error(SessionService::ERROR_ACCESS_DENIED);
		}
		$realFileId = (int)$file->getRealObjectId();
		$result = $transaction->executeRoot(static function (AuditTransaction $owner) use (
			$connection,
			$realFileId,
			$mutation,
		): AuditTransactionOutcome {
			$table = ObjectTable::getTableName();
			$row = $connection->query("SELECT ID FROM {$table} WHERE ID = {$realFileId} FOR UPDATE")->fetch();
			$value = $row
				? $mutation($realFileId, $owner)
				: (new Result())->addError(new Error('access_denied', 'access_denied'));

			return $value->isSuccess()
				? AuditTransactionOutcome::success($value)
				: AuditTransactionOutcome::failure($value);
		});
		foreach ($result->getExceptions() as $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);
		}
		$value = $result->getOutcome()->getValue();

		return ($result->isSuccess() || $result->isRollbackConfirmed()) && $value instanceof Result
			? $value
			: $this->error(self::ERROR_UNAVAILABLE);
	}

	private function read(int $realFileId): ?array
	{
		$lock = $this->storage->get($this->key($realFileId));
		if ($lock === null)
		{
			return null;
		}
		if (
			!is_array($lock)
			|| !is_string($lock['operationId'] ?? null)
			|| !self::isValidOperationId($lock['operationId'])
			|| !is_int($lock['chatId'] ?? null) || $lock['chatId'] <= 0
			|| !is_int($lock['userId'] ?? null) || $lock['userId'] <= 0
			|| !is_int($lock['expiresAt'] ?? null)
		)
		{
			throw new \UnexpectedValueException('Invalid HTML AI lock state.');
		}

		return $lock['expiresAt'] > ($this->clock)() ? $lock : null;
	}

	private function publishAfterCommit(
		AuditTransaction $transaction,
		string $eventName,
		int $fileId,
		array $lock,
	): void
	{
		$payload = ['fileId' => $fileId] + $this->toSnapshot($lock);
		$transaction->addAfterConfirmedCommit(static function () use ($eventName, $payload): void {
			(new Event('disk', $eventName, $payload))->send();
		});
	}

	private function toSnapshot(array $lock): array
	{
		return [
			'operationId' => $lock['operationId'],
			'expiresAt' => gmdate('c', $lock['expiresAt']),
		];
	}

	private function unchanged(?array $lock): Result
	{
		return (new Result())->setData([
			'changed' => false,
			'aiLock' => $lock === null ? null : $this->toSnapshot($lock),
		]);
	}

	private function key(int $realFileId): string
	{
		return 'disk:html-ai-lock:' . $realFileId;
	}

	private function error(string $code): Result
	{
		return (new Result())->addError(new Error($code, $code));
	}
}
