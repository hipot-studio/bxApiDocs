<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\Comment\EventHandler;

use Bitrix\Disk\Document\Flipchart\Annotation\AnnotationService;
use Bitrix\Disk\Document\Flipchart\Comment\AttachmentService;
use Bitrix\Disk\Document\Flipchart\Comment\BoardLock;
use Bitrix\Disk\Document\Flipchart\Comment\CommentMentionTable;
use Bitrix\Disk\Document\Flipchart\Comment\CommentReactionTable;
use Bitrix\Disk\Document\Flipchart\Comment\CommentReadAllTable;
use Bitrix\Disk\Document\Flipchart\Comment\CommentReadTable;
use Bitrix\Disk\Document\Flipchart\Comment\CommentTable;
use Bitrix\Disk\Document\Flipchart\Comment\CommentThreadTable;
use Bitrix\Disk\Document\Flipchart\Comment\ThreadStateTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Event;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

final class BoardDeletedHandler
{
	private const QUERY_CHUNK_SIZE = 500;

	public static function handle(Event $event): void
	{
		$objectId = (int)($event->getParameters()[0] ?? 0);
		if ($objectId <= 0)
		{
			return;
		}

		try
		{
			self::deleteBoardComments($objectId);
		}
		catch (\Throwable $exception)
		{
			self::logException($exception);
		}
	}

	private static function deleteBoardComments(int $objectId): void
	{
		if (!BoardLock::acquire($objectId))
		{
			throw new SystemException(sprintf('Board %d could not be locked to drop its comments.', $objectId));
		}

		try
		{
			self::deleteBoardRows($objectId);
		}
		finally
		{
			try
			{
				BoardLock::release($objectId);
			}
			catch (\Throwable $exception)
			{
				self::logException($exception);
			}
		}
	}

	private static function deleteBoardRows(int $objectId): void
	{
		$connection = Application::getConnection();
		$deletedAt = $connection->getSqlHelper()->convertToDbDateTime(new DateTime());
		$connection->queryExecute(new SqlExpression(
			"UPDATE b_disk_flipchart_comment_thread SET DELETED_AT = {$deletedAt}"
			. ' WHERE OBJECT_ID = ?i AND DELETED_AT IS NULL',
			$objectId,
		));
		AnnotationService::deleteBoard($objectId);

		foreach (array_chunk(self::loadThreadIds($objectId), self::QUERY_CHUNK_SIZE) as $threadChunk)
		{
			$connection->queryExecute(new SqlExpression(
				"UPDATE b_disk_flipchart_comment SET DELETED_AT = {$deletedAt}"
				. ' WHERE THREAD_ID IN (?@) AND DELETED_AT IS NULL',
				$threadChunk,
			));
			ThreadStateTable::deleteByFilter(Query::filter()->whereIn('THREAD_ID', $threadChunk));
			if (!CommentReadTable::deleteByFilter(['=THREAD_ID' => $threadChunk]))
			{
				throw new SystemException('Could not delete comment read state.');
			}
			foreach (array_chunk(self::loadCommentRowIds($threadChunk), self::QUERY_CHUNK_SIZE) as $commentChunk)
			{
				if (!CommentReactionTable::deleteByFilter(['=COMMENT_ROW_ID' => $commentChunk]))
				{
					throw new SystemException('Could not delete comment reactions.');
				}
				// DeleteByFilterTrait returns void and raises on failure instead of answering bool.
				CommentMentionTable::deleteByFilter(['=COMMENT_ROW_ID' => $commentChunk]);
			}
		}

		if (!CommentReadAllTable::deleteByFilter(['=OBJECT_ID' => $objectId]))
		{
			throw new SystemException('Could not delete board read state.');
		}
		AttachmentService::deleteBoardAttachments($objectId);
	}

	/**
	 * @return list<string>
	 */
	private static function loadThreadIds(int $objectId): array
	{
		$threadIds = [];
		$result = CommentThreadTable::query()
			->setSelect(['THREAD_ID'])
			->where('OBJECT_ID', $objectId)
			->exec()
		;
		while ($row = $result->fetch())
		{
			$threadIds[] = (string)$row['THREAD_ID'];
		}

		return $threadIds;
	}

	/**
	 * @param list<string> $threadIds
	 * @return list<int>
	 */
	private static function loadCommentRowIds(array $threadIds): array
	{
		$rowIds = [];
		$result = CommentTable::query()
			->setSelect(['ID'])
			->whereIn('THREAD_ID', $threadIds)
			->exec()
		;
		while ($row = $result->fetch())
		{
			$rowIds[] = (int)$row['ID'];
		}

		return $rowIds;
	}

	private static function logException(\Throwable $exception): void
	{
		try
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);
		}
		catch (\Throwable)
		{
		}
	}
}
