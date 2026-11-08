<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\WebForm\Section;

use Bitrix\Crm\V2\Internal\Entity\WebForm\Section\Responsible;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebForm;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormChangeSet;
use Bitrix\Crm\WebForm\Internals\QueueTable;
use Bitrix\Main\Result;

/**
 * Writes the responsible queue of a CRM form, mirroring ResponsibleSectionLoader.
 *
 * The queue is rewritten as a whole and keeps the input as it is: repeated users are stored more
 * than once, the way the legacy queue did, because a user takes his turn once per row. The
 * rotation pointer of the queue is a portal option and survives the rewrite.
 *
 * The work time check belongs to the queue, not to a single row: every row carries the same flag,
 * and an empty queue holds it nowhere. A change set that carries the flag alone leaves the queue
 * as it is and rewrites the flag of its rows.
 *
 * @internal
 */
final class ResponsibleSectionWriter extends WebFormSectionWriter
{
	private const USER_IDS_PATH = WebForm::SECTION_RESPONSIBLE . '.' . Responsible::FIELD_USER_IDS;
	private const CHECK_WORK_TIME_PATH =
		WebForm::SECTION_RESPONSIBLE . '.' . Responsible::FIELD_CHECK_WORK_TIME;

	public function getSectionName(): string
	{
		return WebForm::SECTION_RESPONSIBLE;
	}

	protected function writeTouched(int $formId, WebFormChangeSet $changeSet): Result
	{
		$storedRows = self::loadStoredRows($formId);
		$checkWorkTime = $changeSet->has(self::CHECK_WORK_TIME_PATH)
			? (bool)$changeSet->get(self::CHECK_WORK_TIME_PATH)
			: self::isStoredCheckWorkTime($storedRows)
		;

		if (!$changeSet->has(self::USER_IDS_PATH))
		{
			return self::writeCheckWorkTime($storedRows, $checkWorkTime);
		}

		/** @var int[] $userIds */
		$userIds = $changeSet->get(self::USER_IDS_PATH);

		return self::replaceQueue($formId, $userIds, $checkWorkTime);
	}

	/**
	 * @param int[] $userIds
	 */
	private static function replaceQueue(int $formId, array $userIds, bool $checkWorkTime): Result
	{
		self::deleteFormRows(QueueTable::class, $formId);

		$result = new Result();
		foreach ($userIds as $userId)
		{
			$addResult = QueueTable::add([
				'FORM_ID' => $formId,
				'USER_ID' => $userId,
				'WORK_TIME' => self::toFlag($checkWorkTime),
			]);
			if (!self::copyErrors($addResult, $result))
			{
				return $result;
			}
		}

		return $result;
	}

	/**
	 * @param array<array{ID: int, WORK_TIME: bool}> $storedRows
	 */
	private static function writeCheckWorkTime(array $storedRows, bool $checkWorkTime): Result
	{
		$result = new Result();
		foreach ($storedRows as $storedRow)
		{
			if ($storedRow['WORK_TIME'] === $checkWorkTime)
			{
				continue;
			}

			$updateResult = QueueTable::update($storedRow['ID'], ['WORK_TIME' => self::toFlag($checkWorkTime)]);
			if (!self::copyErrors($updateResult, $result))
			{
				return $result;
			}
		}

		return $result;
	}

	/**
	 * The queue holds one flag, so the last row answers for all of them, the way the read layer
	 * takes it.
	 *
	 * @param array<array{ID: int, WORK_TIME: bool}> $storedRows
	 */
	private static function isStoredCheckWorkTime(array $storedRows): bool
	{
		$lastRow = $storedRows === [] ? null : $storedRows[array_key_last($storedRows)];

		return $lastRow !== null && $lastRow['WORK_TIME'];
	}

	/**
	 * @return array<array{ID: int, WORK_TIME: bool}>
	 */
	private static function loadStoredRows(int $formId): array
	{
		$rows = QueueTable::query()
			->setSelect(['ID', 'WORK_TIME'])
			->where('FORM_ID', $formId)
			->setOrder(['ID' => 'ASC'])
			->fetchAll()
		;

		$storedRows = [];
		foreach ($rows as $row)
		{
			$storedRows[] = [
				'ID' => (int)$row['ID'],
				'WORK_TIME' => $row['WORK_TIME'] === true || $row['WORK_TIME'] === 'Y',
			];
		}

		return $storedRows;
	}
}
