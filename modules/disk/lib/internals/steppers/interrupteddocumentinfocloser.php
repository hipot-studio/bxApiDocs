<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internals\Steppers;

use Bitrix\Disk\Document\Models\DocumentInfoTable;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\Internal\Service\OnlyOffice\Save\InterruptedSessionService;
use Bitrix\Disk\Internal\Service\OnlyOffice\Save\SavingMarker;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Update\Stepper;

/**
 * Closes the editing sessions the portal left open before it learned to close them.
 *
 * A session whose callback never arrived stayed "being edited" for good, and the documents behind such
 * rows are read as busy to this day. The agent of the feature reaches only what fell silent inside the
 * window in which the editor server still holds the document; what is older is closed once, here.
 *
 * The two differ in more than age. The agent ends a session under the key the editor server knows,
 * because the editing may still come back to it. Beyond the window nothing is held under that key any
 * more, so this stepper also lets the key go: the sessions behind it are deactivated, and the next
 * opening starts on the document Disk has. Without that the row would keep a key which outlived what it
 * pointed at - {@see \Bitrix\Disk\Document\Models\DocumentInfo::isAbandoned()} answers about unfinished
 * rows only, so after this status nothing else would ever rotate it.
 *
 * The moment of the cut-off is taken at the first step and kept for the rest of them: a portion closed
 * an hour later must not reach rows which fell silent meanwhile - those belong to the agent. Nobody is
 * notified: the users left those documents long ago.
 */
// Not final: the reading of a portion is the seam a test replaces to hand back a key whose row has
// stopped being silent, which is what the update has to refuse.
class InterruptedDocumentInfoCloser extends Stepper
{
	protected static $moduleId = 'disk';

	private const ROWS_PER_STEP = 500;

	private const UNFINISHED_STATUSES = [
		DocumentInfoTable::CONTENT_STATUS_EDITING,
		DocumentInfoTable::CONTENT_STATUS_FORCE_SAVED_WITH_ERROR,
		DocumentInfoTable::CONTENT_STATUS_FORCE_SAVED,
	];

	/**
	 * @inheritDoc
	 */
	public function execute(array &$option): bool
	{
		if (!isset($option['cutOffTimestamp']))
		{
			$option['cutOffTimestamp'] = time() - InterruptedSessionService::WINDOW;
			$option['steps'] = 0;
		}

		// The backlog is not counted. Counting it is the one question here whose price grows with the
		// whole history of the portal, and it would be asked before the step has saved anything at all:
		// the state of a stepper reaches the database only after `execute()` returns, so a count which
		// does not fit into one run would be started again by every run and the backlog would never
		// move. The end of the work is told by an empty portion instead, which costs one limited read.
		$hashes = $this->getHashesToClose($this->getCutOff($option));
		if (empty($hashes))
		{
			return self::FINISH_EXECUTION;
		}

		$hashes = $this->dropKeysBeingSaved($hashes);
		if (empty($hashes))
		{
			// A portion entirely under the mark is being written by webhooks right now, and the marks
			// outlive no webhook by more than its budget. The run is asked for again rather than ended:
			// a step which gave up here would take the whole of the remaining backlog with it, and the
			// stepper only ever runs once per tick of its agent anyway.
			return self::CONTINUE_EXECUTION;
		}

		$closed = $this->closeRows($hashes, $this->getCutOff($option));
		if ($closed === null)
		{
			return self::FINISH_EXECUTION;
		}

		$this->releaseKeys($closed);

		$option['steps'] += count($closed);

		return self::CONTINUE_EXECUTION;
	}

	private function getCutOff(array $option): DateTime
	{
		return DateTime::createFromTimestamp((int)$option['cutOffTimestamp']);
	}

	/**
	 * Not private: a test hands back a key whose row has stopped being silent, which is what the update
	 * has to refuse and what no arrangement of the rows alone can produce.
	 *
	 * @return string[]
	 */
	protected function getHashesToClose(DateTime $cutOff): array
	{
		// No order is asked for: any rows matching the filter will do, and the ones taken leave the
		// selection with the status the step writes. An order by UPDATE_TIME over three status ranges
		// cannot come from IX_DISK_DOCUMENT_INFO_STATUS_TIME, so it would turn the limit into a limit on
		// the output only and make every step read the whole of the remaining backlog.
		$rows = DocumentInfoTable::getList([
			'select' => ['EXTERNAL_HASH'],
			'filter' => $this->makeFilter($cutOff),
			'limit' => self::ROWS_PER_STEP,
		])->fetchAll();

		return array_column($rows, 'EXTERNAL_HASH');
	}

	/**
	 * A final save being written right now is silent in exactly the same way as an abandoned session: the
	 * last heartbeat of the key may be old and the callback of the editor server late. Closing such a row
	 * loses no version - the callback writes the real outcome afterwards - but until it arrives the portal
	 * would be telling the participants the editing was interrupted while their document is on its way.
	 *
	 * The whole portion is asked about at once, so the check costs the step one question and not one per
	 * row.
	 *
	 * @param string[] $hashes
	 * @return string[]
	 */
	private function dropKeysBeingSaved(array $hashes): array
	{
		$savingMarker = ServiceLocator::getInstance()->get(SavingMarker::class);
		$beingSaved = $savingMarker->findActive($hashes);

		return empty($beingSaved) ? $hashes : array_values(array_diff($hashes, $beingSaved));
	}

	private function makeFilter(DateTime $cutOff): array
	{
		return [
			'@CONTENT_STATUS' => self::UNFINISHED_STATUSES,
			'<UPDATE_TIME' => $cutOff,
		];
	}

	/**
	 * @param string[] $hashes
	 * @return string[]|null the keys this step ended, or null when the database refused the write.
	 */
	private function closeRows(array $hashes, DateTime $cutOff): ?array
	{
		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();

		$quotedHashes = implode(',', array_map(
			static fn (string $hash): string => "'" . $sqlHelper->forSql($hash) . "'",
			$hashes,
		));

		$tableName = DocumentInfoTable::getTableName();
		$interruptedStatus = DocumentInfoTable::CONTENT_STATUS_INTERRUPTED;
		$unfinishedStatuses = implode(',', self::UNFINISHED_STATUSES);
		$now = $sqlHelper->convertToDbDateTime(new DateTime());
		$quotedCutOff = $sqlHelper->convertToDbDateTime($cutOff);

		// The whole filter of the selection is asked again, not the status alone: a callback may have
		// written the real outcome of a row since it was read, and a page which went on working may have
		// reported itself - the silence the portion was taken for is over either way, and that row is no
		// longer the one the decision was made about.
		$sql = "
			UPDATE {$tableName}
			SET CONTENT_STATUS = {$interruptedStatus}, UPDATE_TIME = {$now}
			WHERE EXTERNAL_HASH IN ({$quotedHashes})
				AND CONTENT_STATUS IN ({$unfinishedStatuses})
				AND UPDATE_TIME < {$quotedCutOff}
		";

		try
		{
			$connection->queryExecute($sql);
		}
		catch (SqlException $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return null;
		}

		return $this->readClosedKeys($hashes);
	}

	/**
	 * Which of the portion the write actually took. The rest stopped being silent between the reading and
	 * the write, and the key of such a row is the one its page is still working under.
	 *
	 * @param string[] $hashes
	 * @return string[]
	 */
	private function readClosedKeys(array $hashes): array
	{
		$rows = DocumentInfoTable::getList([
			'select' => ['EXTERNAL_HASH'],
			'filter' => [
				'@EXTERNAL_HASH' => $hashes,
				'=CONTENT_STATUS' => DocumentInfoTable::CONTENT_STATUS_INTERRUPTED,
			],
		])->fetchAll();

		return array_column($rows, 'EXTERNAL_HASH');
	}

	/**
	 * Lets the key of an ended session go: a document silent for longer than the editor server keeps it
	 * is held nowhere any more, so the next opening has to start on the document Disk has instead of
	 * coming back to a key which points at nothing.
	 *
	 * Deactivating the sessions is what the opening reads:
	 * {@see \Bitrix\Disk\Document\SessionManager::findOrCreateSession()} answers a deactivated session
	 * with a clone under a new key.
	 *
	 * @param string[] $hashes
	 */
	private function releaseKeys(array $hashes): void
	{
		DocumentSessionTable::deactivateByHashes($hashes);
	}
}
