<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityChangeSet;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Answers whether the reminder of the activity changed: row 25 of the owner table STEP-UPDATE
 * (crm_activity.php:807-813). Unconditional, and it interrupts nothing.
 *
 * TWO COLUMNS, ONE CHANGE. Legacy compares the type of the notification and its value and treats a difference of
 * either as the same single fact (:808-813), so the set of changes carries them under one name
 * ({@see ActivityChangeSet::notify}) and this row supplies both halves of it at once. The shape of the pair - the
 * type beside the value - is the one the set validates and the one the wrapper of a legacy provider reads back out
 * of it.
 *
 * THE COLUMNS ARE READ OFF THE TRANSITS, because the typed model holds neither of them: they are columns of the
 * storage and of nothing else. The state read before the write carries them in its own transit, and the transit of
 * the run received them from the re-read of row 17, which fills in every column no step of the run has answered.
 * A column no transit carries reads as null here - legacy can never see that, both of its sides being whole rows -
 * and null is a value these columns really have.
 *
 * WHAT A CHANGE COSTS. The aggregate of the uncompleted activities has to be recomputed (:812) and nothing else:
 * the queue of the reminders belongs to the deadline and to the completion, and this row is the one member of the
 * `Diff` family that raises a single flag. As everywhere in the family, the flag follows the change while the pair
 * is supplied on every update.
 *
 * A CHANGE OF THE REMINDER ALONE STILL WRITES NOTHING, and that is legacy rather than an omission (the finding of
 * phase 1): the synchronization the flag asks for computes its own difference over the two states, finds none, and
 * returns. The row exists for the pair and for the flag, not for a write of its own.
 *
 * WHO READS THE PAIR. The two rows that hand the activity to its legacy provider: the entity the provider
 * associated with the activity is updated with the current side of the pair (row 38), and the hook of the provider
 * after an update receives the previous one (row 46) - both through the projection of the set of changes onto a
 * legacy field array. Legacy hands those providers the whole rows it holds, which carry the two columns, so a run
 * that left the pair out would hand them a row with no reminder in it.
 *
 * @internal
 */
final class DiffNotify implements PipelineStepInterface
{
	/**
	 * The run reached this step without the state the activity was in. There is nothing to compare against.
	 */
	public const ERROR_PREVIOUS_STATE_NOT_LOADED = 'CRM_ACTIVITY_PREVIOUS_STATE_NOT_LOADED';

	private const COLUMN_TYPE = 'NOTIFY_TYPE';
	private const COLUMN_VALUE = 'NOTIFY_VALUE';

	public function getName(): string
	{
		return 'DiffNotify';
	}

	/**
	 * Every update answers the question. What a change of the answer costs is decided inside.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return true;
	}

	public function execute(PipelineContext $context): Result
	{
		$result = new Result();

		if (!$context->hasPreviousState())
		{
			return $result->addError(new Error(
				'The previous state of the activity was not loaded.',
				self::ERROR_PREVIOUS_STATE_NOT_LOADED,
			));
		}

		$changes = $context->getChanges();
		$changes->set(
			ActivityChangeSet::notify,
			self::reminderOf($context->getPreviousTransit()),
			self::reminderOf($context->getTransit()),
		);

		if ($changes->isChanged(ActivityChangeSet::notify))
		{
			$context->setRunValue(PipelineContext::RUN_VALUE_UNCOMPLETED_AGGREGATE_NEEDS_REFRESH, true);
		}

		return $result;
	}

	/**
	 * Legacy interrupts an update on the state it loads, on the check of the fields and on the re-read of the row -
	 * and on nothing else.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * @return array{type: ?int, value: ?int}
	 */
	private static function reminderOf(StorageFieldTransit $transit): array
	{
		return [
			'type' => self::columnOf($transit, self::COLUMN_TYPE),
			'value' => self::columnOf($transit, self::COLUMN_VALUE),
		];
	}

	/**
	 * The column as a number, or null where the transit has nothing to say about it. The storage answers these
	 * two as numbers, and a numeric string of one is the same reminder - which is the cast legacy never had to
	 * make, both of its sides coming out of one fetch.
	 */
	private static function columnOf(StorageFieldTransit $transit, string $column): ?int
	{
		if (!$transit->has($column))
		{
			return null;
		}

		$value = $transit->get($column);

		return $value === null ? null : (int)$value;
	}
}
