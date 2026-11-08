<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add;

use Bitrix\Crm\Activity\FillActLightCounter;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\DeadlineSentinelGuard;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use CCrmActivityNotifyType;

/**
 * Fills in the moment the counter of the activity turns light at: step 20 of the owner table of an add
 * (crm_activity.php:305-306).
 *
 * WHAT THE LIGHT COUNTER IS. The counters of a CRM object show an activity as "coming up" some minutes before
 * its deadline, and that moment is stored per activity in a table of its own. It is derived from the deadline
 * and from whichever of the reminders applies - the resolved offsets first, the notification pair of the
 * activity next, a portal-wide default last - and the derivation belongs to the filler of legacy, which this
 * step asks. An activity with no deadline gets the sentinel of "never", which is a moment as well.
 *
 * THE STEP IS UNCONDITIONAL AND THE RULE "NOT FOR A COMPLETED ONE" LIVES INSIDE THE FILLER. Legacy calls the
 * filler on every add and the filler answers null for a completed activity without writing a row. Lifting that
 * rule into the applicability of the step would be a regression: the monitor of the counters below reads the
 * value of every activity, completed ones included, and it would be left without an answer to read
 * (key constraint of `[P5.T3]`, checklist item C7.3).
 *
 * THE ANSWER IS A VALUE OF THE RUN. Two steps below need it - the aggregate of the uncompleted activities and
 * the monitor of the counters - and legacy hands it to both through its field array, under a key that is no
 * column of an activity. So it travels in {@see PipelineContext::RUN_VALUE_LIGHT_COUNTER_AT}, and it is put
 * there even when it is null: "the step has run and there is no such moment" is an answer, while "nobody
 * produced it" is not.
 *
 * THE DEADLINE IS READ PROTECTEDLY, and the sentinel is handed over as no deadline at all
 * ({@see DeadlineSentinelGuard}). Legacy reaches this step with the key absent whenever the activity has no
 * deadline of its own, and the derivation treats an absent key and the sentinel alike; V2 carries the sentinel
 * in the column instead, so the two shapes have to be made one here.
 *
 * @internal
 */
final class SyncLightCounter implements PipelineStepInterface
{
	/**
	 * The counter is keyed by the activity, so there is nothing to write before the row exists. Legacy cannot
	 * reach this state: it interrupts an add on a failed insert.
	 */
	public const ERROR_NOT_STORED = 'CRM_ACTIVITY_LIGHT_COUNTER_WITHOUT_ACTIVITY';

	private const COLUMN_NOTIFY_TYPE = 'NOTIFY_TYPE';
	private const COLUMN_NOTIFY_VALUE = 'NOTIFY_VALUE';

	public function getName(): string
	{
		return 'SyncLightCounter';
	}

	/**
	 * Every add fills the counter in. See the note of the class on why the completion is not asked here.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return true;
	}

	public function execute(PipelineContext $context): Result
	{
		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return (new Result())->addError(new Error('The activity has not been stored.', self::ERROR_NOT_STORED));
		}

		$lightTime = (new FillActLightCounter())->onActAdd(
			self::legacyFieldsOf($context, $activityId),
			self::offsetsOf($context),
		);
		$context->setRunValue(PipelineContext::RUN_VALUE_LIGHT_COUNTER_AT, $lightTime);

		return new Result();
	}

	/**
	 * An activity whose light counter could not be written is stored all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * The activity in the legacy form the filler reads it in: the four values the derivation is made of, and
	 * nothing besides them. The moment travels as the text of the site, which is the shape legacy parses it back
	 * out of the field array from.
	 *
	 * @return array<string, mixed>
	 */
	private static function legacyFieldsOf(PipelineContext $context, int $activityId): array
	{
		$transit = $context->getTransit();
		$fields = [
			'ID' => $activityId,
			'COMPLETED' => $context->getActivity()->isCompleted() === true ? 'Y' : 'N',
			'NOTIFY_TYPE' => self::intOf($transit, self::COLUMN_NOTIFY_TYPE, CCrmActivityNotifyType::None),
			'NOTIFY_VALUE' => self::intOf($transit, self::COLUMN_NOTIFY_VALUE, 0),
		];

		$deadline = DeadlineSentinelGuard::readFrom($transit);
		if ($deadline !== null)
		{
			$fields[DeadlineSentinelGuard::COLUMN] = (string)$deadline;
		}

		return $fields;
	}

	/**
	 * The reminders the run resolved. The model is the fallback for a run whose resolving step failed: reading
	 * it is not a second resolution - the defaults of the provider are asked for in that step alone.
	 *
	 * @return int[]
	 */
	private static function offsetsOf(PipelineContext $context): array
	{
		if ($context->hasRunValue(PipelineContext::RUN_VALUE_PING_OFFSETS))
		{
			return $context->getRunValue(PipelineContext::RUN_VALUE_PING_OFFSETS);
		}

		return $context->getActivity()->getPingOffsets() ?? [];
	}

	private static function intOf(StorageFieldTransit $transit, string $column, int $default): int
	{
		return $transit->has($column) ? (int)$transit->get($column) : $default;
	}
}
