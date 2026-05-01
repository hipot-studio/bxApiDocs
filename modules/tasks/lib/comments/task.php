<?php

namespace Bitrix\Tasks\Comments;

use Bitrix\Forum\MessageTable;
use Bitrix\Main;
use Bitrix\Main\Entity\ExpressionField;
use Bitrix\Main\Entity\Query;
use Bitrix\Main\Entity\Query\Join;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Integration\Forum;
use Bitrix\Tasks\Internals\Task\ViewedTable;
use Bitrix\Tasks\Internals\TaskTable;
use COption;

/**
 * Class Task
 *
 * @package Bitrix\Tasks\Comments
 */
class Task
{
	/**
	 * @param array $taskIds
	 * @param int $userId
	 * @return array
	 * @throws Main\ArgumentException
	 * @throws Main\ObjectPropertyException
	 * @throws Main\SystemException
	 */
	public static function getNewCommentsCountForTasks(array $taskIds, int $userId): array
	{
		if (empty($taskIds) || !Forum::includeModule())
		{
			return [];
		}

		$query = (new Query(TaskTable::class))
			->setSelect([
				'TASK_ID' => 'ID',
				new ExpressionField('CNT', 'COUNT(DISTINCT %s)', 'FM.ID'),
			])
			->registerRuntimeField('TV', new ReferenceField(
				'TV',
				ViewedTable::getEntity(),
				Join::on('this.ID', 'ref.TASK_ID')->where('ref.USER_ID', $userId),
				['join_type' => 'left']
			))
			->registerRuntimeField('FM', new ReferenceField(
				'FM',
				MessageTable::getEntity(),
				Join::on('this.FORUM_TOPIC_ID', 'ref.TOPIC_ID'),
				['join_type' => 'inner']
			))
			->whereIn('ID', $taskIds)
			->where(
				Query::filter()
					->logic('or')
					->where(
						Query::filter()
							->whereNotNull('TV.VIEWED_DATE')
							->whereColumn('FM.POST_DATE', '>', 'TV.VIEWED_DATE')
					)
					->where(
						Query::filter()
							->whereNull('TV.VIEWED_DATE')
							->whereColumn('FM.POST_DATE', '>=', 'CREATED_DATE')
					)
			)
			->where('FM.NEW_TOPIC', 'N')
			->where(
				Query::filter()
					->logic('or')
					->where(
						Query::filter()
							->where('FM.AUTHOR_ID', '<>', $userId)
							->where(
								Query::filter()
									->logic('or')
									->whereNull('FM.UF_TASK_COMMENT_TYPE')
									->where('FM.UF_TASK_COMMENT_TYPE', '<>', Internals\Comment::TYPE_EXPIRED)
							)
					)
					->where('FM.UF_TASK_COMMENT_TYPE', Internals\Comment::TYPE_EXPIRED_SOON)
					->where('FM.UF_TASK_COMMENT_TYPE', Internals\Comment::TYPE_ONBOARDING_COMMENT)
			)
		;

		$startCounterDate = COption::GetOptionString("tasks", "tasksDropCommentCounters", null);
		if ($startCounterDate)
		{
			$query->where('FM.POST_DATE', '>', new DateTime($startCounterDate, 'Y-m-d H:i:s'));
		}

		$newComments = array_fill_keys($taskIds, 0);
		$newCommentsResult = $query->exec();
		while ($row = $newCommentsResult->fetch())
		{
			$newComments[$row['TASK_ID']] = (int)$row['CNT'];
		}

		return $newComments;
	}
}
