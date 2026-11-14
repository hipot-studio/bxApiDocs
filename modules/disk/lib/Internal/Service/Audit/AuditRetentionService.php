<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Disk\Internal\Enum\AuditRetentionPeriod;
use Bitrix\Disk\Internal\Model\AuditEventTable;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Closure;

class AuditRetentionService
{
	public const DEFAULT_BATCH_SIZE = 500;
	public const ERROR_INVALID_BATCH_SIZE = 'DISK_AUDIT_INVALID_RETENTION_BATCH_SIZE';

	private Closure $nowProvider;

	public function __construct(
		private ?AuditSettingsService $settingsService = null,
		?callable $nowProvider = null,
		private ?AuditSettingsUpdateLock $updateLock = null,
	)
	{
		$this->settingsService ??= new AuditSettingsService();
		$this->updateLock ??= new AuditSettingsUpdateLock();
		$this->nowProvider = Closure::fromCallable(
			$nowProvider ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable(),
		);
	}

	public function deleteExpired(int $batchSize = self::DEFAULT_BATCH_SIZE): Result
	{
		$result = new Result();
		if ($batchSize <= 0)
		{
			return $result->addError(
				new Error('Audit retention batch size must be greater than zero.', self::ERROR_INVALID_BATCH_SIZE),
			);
		}

		if (!$this->updateLock->acquire())
		{
			return $result->addError(new Error(
				'Could not acquire audit settings update lock.',
				AuditSettingsService::ERROR_SETTINGS_UPDATE_LOCK_FAILED,
			));
		}

		try
		{
			$batchSize = min($batchSize, self::DEFAULT_BATCH_SIZE);
			$boundary = $this->calculateBoundary($this->settingsService->getStoredRetentionPeriod());
			$dateTimeBoundary = DateTime::createFromPhp(\DateTime::createFromImmutable($boundary));
			$ids = $this->selectExpiredIds($dateTimeBoundary, $batchSize);

			if ($ids !== [])
			{
				AuditEventTable::deleteByFilter(['@ID' => $ids]);
			}

			return $result->setData([
				'boundary' => $boundary,
				'deletedCount' => count($ids),
				'hasMore' => count($ids) === $batchSize,
			]);
		}
		finally
		{
			$this->updateLock->release();
		}
	}

	public function calculateBoundary(AuditRetentionPeriod $period): \DateTimeImmutable
	{
		return $period->subtractFrom(($this->nowProvider)());
	}

	/** @return int[] */
	private function selectExpiredIds(DateTime $boundary, int $batchSize): array
	{
		$ids = [];
		$rows = AuditEventTable::query()
			->setSelect(['ID'])
			->where('OCCURRED_AT', '<', $boundary)
			->setOrder([
				'OCCURRED_AT' => 'ASC',
				'ID' => 'ASC',
			])
			->setLimit($batchSize)
			->exec()
		;

		while ($row = $rows->fetch())
		{
			$ids[] = (int)$row['ID'];
		}

		return $ids;
	}
}
