<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\StartStat;

use Bitrix\Bizproc\Internal\Config\StartListOrder;
use Bitrix\Bizproc\Internal\Model\StartStat\WorkflowStartStatTable;
use Bitrix\Bizproc\Starter\Enum\ManualStartSurface;
use Bitrix\Bizproc\Workflow\Entity\WorkflowStateTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * A successfully completed manual start, counted into the daily bucket of the day the process started
 * on. The unit belongs to the start and not to the completion: a process that ran over midnight, or for
 * a week, still says how popular its template was on the day an employee reached for it.
 *
 * The counter is derived statistics of the process and never a part of its result, so every input is
 * validated and every failure - an unusable mark, an unreadable state, a rejected write - ends as a
 * warning in the log and leaves the process alone. The check of the input is inside the guarded block
 * too: the mark comes from the read-only data of an activity and is not guaranteed to be a string at
 * all, and trim() over a value of an unexpected type would throw around the very contract this class
 * promises.
 *
 * Reading the state, writing the bucket and taking the logger are separate methods so that a test
 * double can make each of them fail; nothing else is meant to be overridden.
 */
class SuccessfulManualStartCounter
{
	private const LOGGER_ID = 'bizproc.start_list_order';

	private ?LoggerInterface $logger = null;

	/**
	 * @param mixed $surface Raw mark of the start surface, as the root activity stored it.
	 * @param DateTime $now The moment the retention window is measured back from.
	 */
	public function record(int $templateId, string $workflowId, mixed $surface, DateTime $now): void
	{
		try
		{
			if ($templateId <= 0 || $workflowId === '')
			{
				return;
			}

			if (!is_string($surface) || ManualStartSurface::tryFrom(trim($surface)) === null)
			{
				return;
			}

			$startedAt = $this->readStartedAt($workflowId);
			if ($startedAt === null)
			{
				$this->warn('Bizproc: a completed manual start has no state to take the start day from', [
					'templateId' => $templateId,
					'workflowId' => $workflowId,
					'surface' => $surface,
				]);

				return;
			}

			$statDate = PortalDate::of($startedAt);
			if ($statDate->format('Y-m-d') < $this->oldestKeptDate($now)->format('Y-m-d'))
			{
				// the bucket of that day is already gone, or the cleanup is about to take it
				return;
			}

			$this->incrementBucket($templateId, $statDate);
		}
		catch (Throwable $error)
		{
			$this->warn('Bizproc: a successful manual start was not counted', [
				'templateId' => $templateId,
				'workflowId' => $workflowId,
				'surface' => is_string($surface) ? $surface : get_debug_type($surface),
				'error' => $error->getMessage(),
				'errorClass' => get_class($error),
			]);
		}
	}

	protected function readStartedAt(string $workflowId): ?DateTime
	{
		$row = WorkflowStateTable::getList([
			'select' => ['STARTED'],
			'filter' => ['=ID' => $workflowId],
			'limit' => 1,
		])->fetch();

		$startedAt = is_array($row) ? $row['STARTED'] : null;

		return $startedAt instanceof DateTime ? $startedAt : null;
	}

	/**
	 * Two completions of one template and one day meet on the primary key, so the bucket is raised by a
	 * merge: parallel completions add up instead of fighting over the row, and the query stays the same
	 * on both supported databases.
	 */
	protected function incrementBucket(int $templateId, Date $statDate): void
	{
		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$tableName = WorkflowStartStatTable::getTableName();

		$queries = $sqlHelper->prepareMerge(
			$tableName,
			['TEMPLATE_ID', 'STAT_DATE'],
			[
				'TEMPLATE_ID' => $templateId,
				'STAT_DATE' => $statDate,
				'CNT' => 1,
			],
			[
				'CNT' => new SqlExpression('?#.?# + ?i', $tableName, 'CNT', 1),
			],
		);

		foreach ($queries as $query)
		{
			if ($query !== '')
			{
				$connection->queryExecute($query);
			}
		}
	}

	protected function getLogger(): LoggerInterface
	{
		return $this->logger ??= (new LoggerFactory())->createById(self::LOGGER_ID) ?? new NullLogger();
	}

	/**
	 * The oldest day still worth a bucket: the day the retention window starts on, the control moment
	 * included in the window.
	 */
	private function oldestKeptDate(DateTime $now): Date
	{
		$keptDays = StartListOrder::getEffectiveRetentionDays();

		return PortalDate::of($now)->add('-' . ($keptDays - 1) . ' days');
	}

	/**
	 * @param array<string, mixed> $context Safe identifiers of the start only - never its parameters,
	 * its document or the values of its fields.
	 */
	private function warn(string $message, array $context): void
	{
		try
		{
			$this->getLogger()->warning($message, $context);
		}
		catch (Throwable)
		{
			// a broken log must not turn a skipped unit of statistics into a broken process
		}
	}
}
