<?php

namespace Bitrix\Mobile\Internal\Onboarding\Checkers;

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

/**
 * Base of the step checks that answer "did this user leave a record of their own since the
 * anchor of the series" - a task, a deal, an invitation. All of them ask one table for the
 * existence of one row, and all of them are useless without the module that owns the table.
 *
 * Reading a whole selection at once is NOT part of this base: whether it pays off depends on
 * the indexes of the table, and it does not always. A check whose per-user query already lands
 * on a narrow index stays here; one that re-reads a date range per user extends
 * BulkRecordExistsStepChecker instead.
 */
abstract class RecordExistsStepChecker implements StepChecker
{
	abstract protected function getModuleId(): string;

	/**
	 * Whether the single user left a record.
	 */
	abstract protected function recordExists(int $userId, DateTime $sinceDate): bool;

	public function isCompleted(int $userId, DateTime $sinceDate): bool
	{
		if ($userId <= 0 || !Loader::includeModule($this->getModuleId()))
		{
			return false;
		}

		return $this->recordExists($userId, $sinceDate);
	}
}
