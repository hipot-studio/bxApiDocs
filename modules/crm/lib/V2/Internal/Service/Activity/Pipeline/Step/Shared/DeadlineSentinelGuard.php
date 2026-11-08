<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityFieldMap;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Main\ObjectException;
use Bitrix\Main\Type\DateTime;
use CCrmDateTimeHelper;

/**
 * The deadline of an activity as the steps of a pipeline are allowed to read it: the column says "no
 * deadline" with a date of the year 9999 rather than with NULL, and it may not be there at all.
 *
 * WHY A STEP MAY NOT JUST READ THE COLUMN. Two traps sit on that read and both are confirmed behaviour of
 * legacy:
 * - the column is often absent from an operation altogether. On an add the deadline usually lives only in
 *   the raw channel until the provider has run, so `$arFields['DEADLINE']` is read while unset and legacy
 *   emits a warning for it (LBS-1 section 15 item 7). The steps registering the statistics and the ping
 *   queue are the two that read it, and neither may reproduce the warning;
 * - the sentinel is not a date. Written to the column it means the absence of a deadline, so a reader
 *   taking it for a real date would register statistics dated the year 9999.
 *
 * WHY TWO DIFFERENT QUESTIONS. Legacy gates the statistics of a deal or a lead with two conditions that
 * are not the same one: the outer one asks whether a deadline was given at all (`!== ''`, which the
 * sentinel satisfies) and the inner, per binding, whether the value is not the sentinel. Collapsing them
 * changes an observable effect: the statistic entry of the activity itself is registered under a sentinel
 * deadline, because its gate is the outer question alone. So {@see self::isGiven()} and
 * {@see self::toDeadline()} are two answers about one value and a step picks the one its gate is written
 * in.
 *
 * WHY THE MODEL IS NOT THE SOURCE. {@see \Bitrix\Crm\V2\Internal\Entity\Activity\Activity::getDeadline()}
 * already answers null for the sentinel - the repository resolves it on the way out - which means a model
 * cannot tell "no deadline" from "the sentinel", and the outer question above needs exactly that
 * difference. A step asking about a state it has re-read asks the model; a step asking about the value the
 * operation is writing asks here, of the transit that carries it.
 *
 * A ROW OF AN UPDATE, HOWEVER, IS ASKED THE OUTER QUESTION ABOUT ITS STATE. Legacy reads both deadlines of an
 * update off the two rows it fetched, where an activity without one carries the sentinel and its outer gates
 * therefore pass; the transit of such a run carries no deadline at all, because an update strips the one of
 * the caller. {@see self::columnOf()} is the value of the column of a state, the sentinel included, and it is
 * the one place saying that a state without a deadline is a row carrying that date.
 *
 * THE SENTINEL HAS ONE DEFINITION and it belongs to the storage boundary: this class is the face the
 * pipeline sees of {@see ActivityFieldMap::getDeadlineSentinel()}, not a second copy of the date. The one
 * step that writes it - the validation forcing an activity of an incoming channel to have no deadline -
 * writes it through {@see self::forceToNoneIn()} rather than by hand.
 *
 * WHY FORCING "NO DEADLINE" NEEDS MORE THAN THE COLUMN. Legacy says "none" by taking the key out of its
 * field array and putting the sentinel into the raw channel alone (:2181-2186), so the outer gate above
 * answers false and nothing of the statistics is registered. V2 says it with the value the row is to carry,
 * and the sentinel in the column passes that very gate - it is a value that was given. The forcing is
 * therefore recorded twice - the value for the write, and the fact for the gates,
 * under {@see PipelineContext::RUN_VALUE_DEADLINE_NOT_GIVEN} - and the gates of the pipeline ask
 * {@see self::isGivenToTheRun()}, which accounts for both. {@see self::isGivenIn()} keeps answering about
 * the column alone: an update finds the sentinel there because it re-read it from storage, nobody forced
 * it, and legacy does register the statistics of that activity.
 *
 * @internal
 */
final class DeadlineSentinelGuard
{
	/**
	 * The column of the transit the deadline of an operation travels in.
	 */
	public const COLUMN = 'DEADLINE';

	/**
	 * The date the column carries instead of NULL to say the activity has no deadline.
	 */
	public static function getSentinel(): DateTime
	{
		return ActivityFieldMap::getDeadlineSentinel();
	}

	/**
	 * Whether the value is that date. Accepts both shapes a deadline travels in: the typed one, and the
	 * literal a provider is allowed to write through the raw channel.
	 */
	public static function isSentinel(mixed $value): bool
	{
		if ($value instanceof DateTime)
		{
			return $value->format('Y') === self::getSentinel()->format('Y');
		}

		if (!is_string($value) || $value === '')
		{
			return false;
		}

		// The literal is quoted by the sql helper, which the parsing predicate of legacy cannot read.
		return $value === CCrmDateTimeHelper::GetMaxDatabaseDate() || CCrmDateTimeHelper::IsMaxDatabaseDate($value);
	}

	/**
	 * Whether a deadline value was given at all - the gate legacy writes as `$deadline !== ''`. THE
	 * SENTINEL COUNTS AS GIVEN: see the note of the class on the two questions.
	 */
	public static function isGiven(mixed $value): bool
	{
		return $value !== null && $value !== '';
	}

	/**
	 * The real deadline the value stands for, or null when there is none: nothing given, an empty value,
	 * or the sentinel.
	 *
	 * A literal that is not a date reads as no deadline instead of raising: this is the guarded read of a
	 * tail step, and a step of the tail that threw would only log the throw and carry on anyway, having
	 * skipped the effect it was there to perform.
	 */
	public static function toDeadline(mixed $value): ?DateTime
	{
		if (self::isSentinel($value))
		{
			return null;
		}

		if ($value instanceof DateTime)
		{
			return $value;
		}

		if (!is_string($value) || $value === '')
		{
			return null;
		}

		try
		{
			// The same parsing legacy performs before it dates the statistics by the deadline.
			return DateTime::createFromUserTime($value);
		}
		catch (ObjectException)
		{
			return null;
		}
	}

	/**
	 * Whether the operation carries a deadline value, the absent column included. The outer gate of the
	 * steps registering the statistics.
	 */
	public static function isGivenIn(StorageFieldTransit $transit): bool
	{
		return $transit->has(self::COLUMN) && self::isGiven($transit->get(self::COLUMN));
	}

	/**
	 * The deadline the operation is writing, or null when it writes none. The guarded read: an absent
	 * column is an answer, not a warning.
	 */
	public static function readFrom(StorageFieldTransit $transit): ?DateTime
	{
		return $transit->has(self::COLUMN) ? self::toDeadline($transit->get(self::COLUMN)) : null;
	}

	/**
	 * The value the column of a stored state carries: the deadline of the model, or the sentinel where the
	 * model has none. A row read back from storage always carries one of the two, so this is the shape the
	 * readers legacy feeds off its fetched snapshots need - the aggregators of an update among them, which
	 * take the sentinel for a date of the year 9999 and register under it.
	 *
	 * THE ONE ROW IT SPEAKS OF WRONGLY carries NULL where the sentinel belongs. Legacy reads such a column as
	 * an empty value and registers nothing, while this answer is the sentinel and something is registered.
	 * Those rows are the ones a repair agent of the module was written for, and telling them from the ones
	 * without a deadline is beyond the boundary of the storage, which resolves both to null.
	 */
	public static function columnOf(Activity $activity): DateTime
	{
		return $activity->getDeadline() ?? self::getSentinel();
	}

	/**
	 * Whether the run carries a deadline value at all - the outer gate of a step, answered the way legacy
	 * answers it. Same question as {@see self::isGivenIn()} except over a run whose deadline was forced to
	 * none: there the column carries the sentinel for the write while legacy carries no key at all. See the
	 * note of the class.
	 */
	public static function isGivenToTheRun(PipelineContext $context): bool
	{
		if (self::isForcedToNoneIn($context))
		{
			return false;
		}

		return self::isGivenIn($context->getTransit());
	}

	/**
	 * Makes the operation write no deadline: the sentinel into the column, over whatever the check of the
	 * fields or a provider has derived there, and the fact of it into the run, so that the outer gates answer
	 * as legacy does. The two belong together and this is the only place they are set.
	 */
	public static function forceToNoneIn(PipelineContext $context): void
	{
		$context->getTransit()->set(self::COLUMN, self::getSentinel());
		$context->setRunValue(PipelineContext::RUN_VALUE_DEADLINE_NOT_GIVEN, true);
	}

	private static function isForcedToNoneIn(PipelineContext $context): bool
	{
		return $context->hasRunValue(PipelineContext::RUN_VALUE_DEADLINE_NOT_GIVEN)
			&& $context->getRunValue(PipelineContext::RUN_VALUE_DEADLINE_NOT_GIVEN) === true
		;
	}
}
