<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\BoardStatistics;

use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Repository\BoardCreationStatisticsRepository;
use Bitrix\Disk\TypeFile;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

/**
 * Decides whether a stored file is a board creation the statistics count: a real object (not a link) of
 * the board type, created by a real user not earlier than the tracked period starts.
 *
 * The month of a creation is the calendar month of CREATE_TIME in the server time of the portal, as
 * disk stores it; no user timezone is applied.
 */
final class BoardCreationTracker
{
	public const TRACKED_SINCE = '2026-01-01 00:00:00';

	public function __construct(private readonly BoardCreationStatisticsRepository $repository)
	{
	}

	public static function trackedSince(): DateTime
	{
		return new DateTime(self::TRACKED_SINCE, 'Y-m-d H:i:s');
	}

	/**
	 * @param array{ID: int, TYPE_FILE: ?int, REAL_OBJECT_ID: ?int, CREATED_BY: int, CREATE_TIME: ?DateTime} $object
	 */
	public static function isTrackedBoard(array $object): bool
	{
		return $object['REAL_OBJECT_ID'] === $object['ID']
			&& $object['TYPE_FILE'] === TypeFile::BOARD
			&& $object['CREATED_BY'] > 0
			&& $object['CREATE_TIME'] instanceof DateTime
			&& $object['CREATE_TIME']->getTimestamp() >= self::trackedSince()->getTimestamp();
	}

	public static function monthOf(DateTime $createTime): Date
	{
		return new Date($createTime->format('Y-m-01'), 'Y-m-d');
	}

	/**
	 * Counts the file when it is a tracked board above the border of the backfill. The state is created
	 * on the first call; when the file itself made the border, its id is within it and the backfill
	 * counts it instead.
	 */
	public function track(File $file): bool
	{
		$object = [
			'ID' => (int)$file->getId(),
			'TYPE_FILE' => $file->getTypeFile() === null ? null : (int)$file->getTypeFile(),
			'REAL_OBJECT_ID' => $file->getRealObjectId() === null ? null : (int)$file->getRealObjectId(),
			'CREATED_BY' => (int)$file->getCreatedBy(),
			'CREATE_TIME' => $file->getCreateTime(),
		];
		if (!self::isTrackedBoard($object))
		{
			return false;
		}

		$state = $this->repository->getState() ?? $this->repository->initializeState();
		if ($object['ID'] <= $state->maxObjectId)
		{
			return false;
		}

		$this->repository->increment($object['CREATED_BY'], self::monthOf($object['CREATE_TIME']));

		return true;
	}
}
