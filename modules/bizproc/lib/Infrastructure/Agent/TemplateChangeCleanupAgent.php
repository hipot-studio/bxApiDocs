<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Agent;

use Bitrix\Bizproc\Internal\Config\TemplateHistory;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateChangeTable;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Update\Stepper;

/**
 * Stepper deletes template change events older than the retention period. Events still holding a version
 * body are left to the rotation, and the recording flag is not consulted: a portal with the history
 * switched off still has to lose the events it has already collected.
 */
class TemplateChangeCleanupAgent extends Stepper
{
	private const RECORD_LIMIT = 100;

	protected static $moduleId = 'bizproc';

	/**
	 * Run stepper as agent, for periodic runs.
	 */
	public static function runAgent(): string
	{
		self::bind(0);

		return __METHOD__ . '();';
	}

	/**
	 * @inheritDoc
	 */
	public function execute(array &$option)
	{
		$cursor = is_array($option['cursor'] ?? null) ? $option['cursor'] : [];

		$deletedCount = self::deleteExpired(self::RECORD_LIMIT, $cursor);

		$option['cursor'] = $cursor;

		return $deletedCount < self::RECORD_LIMIT ? self::FINISH_EXECUTION : self::CONTINUE_EXECUTION;
	}

	/**
	 * Deletes at most $limit expired events without a version body, oldest first, and reports the size of
	 * the batch it took.
	 *
	 * The events keeping a version body are the oldest ones of the table and are never deleted, so every
	 * step would start by reading them again: $cursor carries the place of the last taken event over to the
	 * next step and is left at the pair `(CREATED, ID)` the index of the cleanup is read through. A pass
	 * ending with FINISH_EXECUTION drops the cursor with the whole option of the stepper, so a version body
	 * dropped by the rotation behind the cursor is reached by the pass of the next day.
	 *
	 * The pass is read through the scalar mirror of the body, HAS_SNAPSHOT, because a blob is out of reach
	 * of an index. The body itself is asked as well: the events of a portal written before that column
	 * carry its default while holding a body, and they belong to the rotation, not to the cleanup.
	 */
	public static function deleteExpired(int $limit, array &$cursor = []): int
	{
		$expiredBefore = (new DateTime())->add('-' . TemplateHistory::getRetentionDays() . ' days');

		$query = WorkflowTemplateChangeTable::query()
			->setSelect(['ID', 'CREATED'])
			->where('HAS_SNAPSHOT', false)
			->where('CREATED', '<', $expiredBefore)
			->whereNull('SNAPSHOT')
			->setOrder(['CREATED' => 'ASC', 'ID' => 'ASC'])
			->setLimit($limit)
		;

		self::applyCursor($query, $cursor);

		$rows = $query->fetchAll();
		if ($rows === [])
		{
			return 0;
		}

		$last = end($rows);
		$lastCreated = $last['CREATED'] ?? null;
		$cursor = $lastCreated instanceof DateTime
			? ['created' => $lastCreated->getTimestamp(), 'id' => (int)$last['ID']]
			: []
		;

		WorkflowTemplateChangeTable::deleteByFilter(['@ID' => array_column($rows, 'ID')]);

		return count($rows);
	}

	private static function applyCursor(Query $query, array $cursor): void
	{
		$created = (int)($cursor['created'] ?? 0);
		$id = (int)($cursor['id'] ?? 0);
		if ($created <= 0 || $id <= 0)
		{
			return;
		}

		$createdAt = DateTime::createFromTimestamp($created);

		$query->where(
			Query::filter()
				->logic('or')
				->where('CREATED', '>', $createdAt)
				->where(
					Query::filter()
						->where('CREATED', $createdAt)
						->where('ID', '>', $id)
				)
		);
	}
}
