<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Infrastructure\Agent\Collab;

use Bitrix\Calendar\Core\Event\Tools\Dictionary;
use Bitrix\Calendar\Internals\EventTable;
use Bitrix\Main\Application;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\SystemException;
use Bitrix\Main\Update\Stepper;
use Throwable;

/**
 * One-shot cleanup of the legacy EVENT_TYPE='#collab#' marker.
 *
 * Under the HAS_COLLABERS-driven model the calendar module never writes
 * '#collab#' anymore and never reads it either — "is this a collab event"
 * is derived from the current HAS_COLLABERS state of the owning group.
 *
 * Historical rows from the period when real collabs existed still carry the
 * marker. Functionally they are inert (no read-path consults it), but the
 * scrubber clears them up so that DB state matches the new convention.
 *
 * EVENT_TYPE is not indexed, so we can't ask the DB to hand us the rare
 * matching rows directly — selecting them with a LIMIT forces a PRIMARY range
 * scan that filters every row until the batch fills, which on a table with
 * millions of events degrades to a full scan per step. Instead we walk the
 * table in fixed PRIMARY-key windows and clear the marker in place: each
 * step touches a bounded range of consecutive IDs regardless of how many
 * (if any) rows match, so step cost stays constant. The upper bound is a
 * MAX(ID) snapshot taken once — events created after the run starts can't
 * carry the marker, so they need no scanning. Window updates are idempotent.
 *
 * Runs without any cross-table joins or group-level coordination. Untouched
 * marker for '#shared_collab#'.
 */
class CollabEventTypeScrubber extends Stepper
{
	/** Width of the PRIMARY-key window scanned per UPDATE. */
	private const ID_WINDOW = 3000;

	protected static $moduleId = 'calendar';

	private array $option;

	public function execute(array &$option): bool
	{
		$this->option = &$option;

		$maxId = $this->getMaxId();

		if ($maxId <= 0)
		{
			return self::FINISH_EXECUTION;
		}

		$lastId = $this->getLastId();

		if ($lastId >= $maxId)
		{
			return self::FINISH_EXECUTION;
		}

		$upperId = min($lastId + self::ID_WINDOW, $maxId);

		try
		{
			EventTable::updateByFilter(
				[
					'=EVENT_TYPE' => Dictionary::EVENT_TYPE['collab'],
					'>ID' => $lastId,
					'<=ID' => $upperId,
				],
				['EVENT_TYPE' => ''],
			);
		}
		catch (Throwable $e)
		{
			Application::getInstance()
				->getExceptionHandler()
				->writeToLog(
					new SystemException(
						sprintf('CollabEventTypeScrubber: window failed (%d, %d]: %s', $lastId, $upperId, $e->getMessage()),
						$e->getCode(),
						previous: $e,
					),
				)
			;

			return self::FINISH_EXECUTION;
		}

		$this->setLastId($upperId);

		return self::CONTINUE_EXECUTION;
	}

	/**
	 * Largest event ID at the moment the scrubber starts. Snapshotted once and
	 * persisted in the option: rows created later can't carry the marker.
	 */
	private function getMaxId(): int
	{
		if (isset($this->option['maxId']))
		{
			return (int)$this->option['maxId'];
		}

		$row = EventTable::query()
			->addSelect(new ExpressionField('MAX_ID', 'MAX(%s)', 'ID'))
			->exec()
			->fetch()
		;

		$maxId = (int)($row['MAX_ID'] ?? 0);
		$this->option['maxId'] = $maxId;

		return $maxId;
	}

	private function getLastId(): int
	{
		return (int)($this->option['lastId'] ?? 0);
	}

	private function setLastId(int $id): void
	{
		$this->option['lastId'] = $id;
	}
}
