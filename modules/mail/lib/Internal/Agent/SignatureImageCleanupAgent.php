<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Agent;

use Bitrix\Mail\Internals\SignatureImageBindingTable;
use Bitrix\Mail\Internals\SignatureImageTable;
use Bitrix\Main\Application;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

final class SignatureImageCleanupAgent
{
	private const BATCH_SIZE = 1000;
	private const RETENTION_PERIOD = '-24 hours';

	public static function getName(): string
	{
		return 'Bitrix\\Mail\\Internal\\Agent\\SignatureImageCleanupAgent::run();';
	}

	public static function run(): string
	{
		$cutoff = self::createCutoff(new DateTime());
		self::cleanupImages(
			self::createBatchLoader(),
			static fn(int $imageId) => self::deleteIfStillUnbound($imageId, $cutoff),
			static fn(\Throwable $exception) => Application::getInstance()
				->getExceptionHandler()
				->writeToLog($exception),
			$cutoff,
		);

		return self::getName();
	}

	private static function cleanupImages(
		callable $loadBatch,
		callable $deleteImage,
		callable $logException,
		DateTime $cutoff,
	): void
	{
		$cursor = null;
		$processed = 0;
		do
		{
			$rows = $loadBatch($cutoff, $cursor, self::BATCH_SIZE - $processed);
			foreach ($rows as $row)
			{
				$imageId = (int)$row['ID'];
				$cursor = [
					'date' => $row['CLEANUP_DATE'],
					'id' => $imageId,
				];
				$processed++;

				try
				{
					$deleteImage($imageId);
				}
				catch (\Throwable $exception)
				{
					try
					{
						$logException($exception);
					}
					catch (\Throwable)
					{
						// Cleanup must continue even if error logging is unavailable.
					}
				}

				if ($processed >= self::BATCH_SIZE)
				{
					return;
				}
			}
		}
		while ($rows !== []);
	}

	private static function createBatchLoader(): callable
	{
		return static function(DateTime $cutoff, ?array $cursor, int $limit): array
		{
			$query = SignatureImageTable::query()
				->setSelect(['ID', 'CLEANUP_DATE' => 'DATE_MODIFY'])
				->registerRuntimeField(
					new Reference(
						'IMAGE_BINDING',
						SignatureImageBindingTable::class,
						Join::on('this.ID', 'ref.IMAGE_ID'),
						['join_type' => Join::TYPE_LEFT],
					),
				)
				->whereIn('STATE', [
					SignatureImageTable::STATE_PENDING,
					SignatureImageTable::STATE_ORPHANED,
					SignatureImageTable::STATE_DELETING,
				])
				->where('DATE_MODIFY', '<=', $cutoff)
				->whereNull('IMAGE_BINDING.IMAGE_ID')
				->setOrder(['DATE_MODIFY' => 'ASC', 'ID' => 'ASC'])
				->setLimit($limit)
			;
			if ($cursor !== null)
			{
				$query->where(
					Query::filter()
						->logic('or')
						->where('DATE_MODIFY', '>', $cursor['date'])
						->where(
							Query::filter()
								->where('DATE_MODIFY', $cursor['date'])
								->where('ID', '>', $cursor['id']),
						),
				);
			}

			return $query->fetchAll();
		};
	}

	private static function deleteIfStillUnbound(int $imageId, DateTime $cutoff): bool
	{
		return self::processDeletion(
			$imageId,
			static fn(int $lockedImageId): ?int => self::prepareDeletionTransaction($lockedImageId, $cutoff),
			static function (int $fileId): void {
				\CFile::Delete($fileId);
			},
			static function (int $lockedImageId): void {
				$result = SignatureImageTable::delete($lockedImageId);
				if (!$result->isSuccess())
				{
					throw new SystemException(implode('; ', $result->getErrorMessages()));
				}
			},
		);
	}

	private static function prepareDeletionTransaction(int $imageId, DateTime $cutoff): ?int
	{
		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$fileId = self::prepareDeletion(
				$imageId,
				static fn(int $lockedImageId): ?array => self::loadLockedImage($lockedImageId, $cutoff),
				static fn(int $lockedImageId): bool => self::hasBinding($lockedImageId),
				static function (int $lockedImageId): void {
					$result = SignatureImageTable::update($lockedImageId, [
						'STATE' => SignatureImageTable::STATE_DELETING,
					]);
					if (!$result->isSuccess())
					{
						throw new SystemException(implode('; ', $result->getErrorMessages()));
					}
				},
			);
			$connection->commitTransaction();

			return $fileId;
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();

			throw $exception;
		}
	}

	private static function processDeletion(
		int $imageId,
		callable $prepareDeletion,
		callable $deleteFile,
		callable $deleteRow,
	): bool
	{
		$fileId = $prepareDeletion($imageId);
		if ($fileId === null)
		{
			return false;
		}

		$deleteFile($fileId);
		$deleteRow($imageId);

		return true;
	}

	private static function prepareDeletion(
		int $imageId,
		callable $loadImage,
		callable $hasBinding,
		callable $markDeleting,
	): ?int
	{
		$image = $loadImage($imageId);
		if ($image === null || $hasBinding($imageId))
		{
			return null;
		}

		if ((string)$image['STATE'] !== SignatureImageTable::STATE_DELETING)
		{
			$markDeleting($imageId);
		}

		return (int)$image['FILE_ID'];
	}

	private static function loadLockedImage(int $imageId, DateTime $cutoff): ?array
	{
		$connection = Application::getConnection();
		$filter = Query::filter()
			->where('ID', $imageId)
			->whereIn('STATE', [
				SignatureImageTable::STATE_PENDING,
				SignatureImageTable::STATE_ORPHANED,
				SignatureImageTable::STATE_DELETING,
			])
			->where('DATE_MODIFY', '<=', $cutoff)
		;

		$row = $connection->query(sprintf(
			'SELECT %1$s, %2$s, %3$s FROM %4$s WHERE %5$s FOR UPDATE',
			$connection->getSqlHelper()->quote('ID'),
			$connection->getSqlHelper()->quote('FILE_ID'),
			$connection->getSqlHelper()->quote('STATE'),
			$connection->getSqlHelper()->quote(SignatureImageTable::getTableName()),
			Query::buildFilterSql(SignatureImageTable::getEntity(), $filter),
		))->fetch();

		return is_array($row) ? $row : null;
	}

	private static function hasBinding(int $imageId): bool
	{
		return (bool)SignatureImageBindingTable::query()
			->setSelect(['IMAGE_ID'])
			->where('IMAGE_ID', $imageId)
			->setLimit(1)
			->fetch()
		;
	}

	private static function createCutoff(DateTime $now): DateTime
	{
		return (clone $now)->add(self::RETENTION_PERIOD);
	}
}
