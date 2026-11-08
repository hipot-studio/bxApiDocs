<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add;

use Bitrix\Crm\Timeline\ActivityController;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityFieldMap;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\DeadlineSentinelGuard;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\RestorationMode;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 * Tells the timeline of the bound objects that an activity has appeared: step 30 of the owner table of an add
 * (crm_activity.php:396-406), checklist item C10.
 *
 * WHAT THE TIMELINE DOES WITH IT. Three different things, and which of them happens is its own decision from the
 * type and the provider of the activity: a record of the history for a letter, a call, a meeting or a task that is
 * already complete, a record of a to-do or of a repeat sale, and a push into the scheduled stream of an activity
 * waiting to be done. The step performs none of that itself - it hands the activity over exactly where legacy
 * does, which is below the calendar and above the user fields.
 *
 * A RESTORATION SILENCES IT. An activity coming back out of the recycle bin is not new to the timeline, and this
 * is one of the three effects of an add the mode switches off ({@see RestorationMode::SUPPRESSED_EFFECTS}).
 *
 * THE ACTIVITY GOES OVER IN THE LEGACY SHAPE, and it has to be the rich one: the timeline reads the type, the
 * status, the provider, the author, the description, the times and the deadline off the array it is given, and
 * hands the very same array on to the factory building the item of the scheduled stream. Three rules of the shape
 * are worth naming:
 * - the columns of the transit beat the fields of the model, exactly as they do when the row is written;
 * - the serialized three travel unserialized, which is the shape legacy has at this point of an add (:233-235) and
 *   the opposite of the shape the event before the write sees (item C4);
 * - the deadline is read through the guard, so an activity nobody gave one is handed no key at all instead of the
 *   sentinel. A sentinel taken for a date would date the record of a to-do in the year 9999.
 *
 * WHAT THE ARRAY DOES NOT CARRY. The columns the storage boundary derives on its own - the priority of an
 * unimportant activity, the direction, the moment of creation of an activity whose caller did not preserve one -
 * because their author is the repository and a second author would be a second answer (the rule of the event
 * before the write, step 6). The bindings are not carried either: the timeline re-reads them out of storage.
 *
 * @internal
 */
final class NotifyTimelineOnCreate implements PipelineStepInterface
{
	/**
	 * The timeline is told about an activity, so there is nothing to tell before the row exists. Legacy cannot
	 * reach this state: it interrupts an add on a failed insert.
	 */
	public const ERROR_NOT_STORED = 'CRM_ACTIVITY_TIMELINE_WITHOUT_ACTIVITY';

	private const COLUMN_LIGHT_COUNTER_AT = 'LIGHT_COUNTER_AT';

	private const COLUMN_IS_INCOMING_CHANNEL = 'IS_INCOMING_CHANNEL';

	private const COLUMN_PING_OFFSETS = 'PING_OFFSETS';

	private readonly ActivityController $timeline;

	/**
	 * @param ActivityController|null $timeline the timeline of the activities; the one seam of the step.
	 */
	public function __construct(?ActivityController $timeline = null)
	{
		$this->timeline = $timeline ?? ActivityController::getInstance();
	}

	public function getName(): string
	{
		return 'NotifyTimelineOnCreate';
	}

	/**
	 * Every add but a restoration. See the note of the class.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return !RestorationMode::isActive($context);
	}

	public function execute(PipelineContext $context): Result
	{
		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return (new Result())->addError(new Error('The activity has not been stored.', self::ERROR_NOT_STORED));
		}

		$operationContext = $context->getOperationContext();

		$this->timeline->onCreate($activityId, [
			'FIELDS' => self::legacyFieldsOf($context, $activityId),
			'PRESERVE_CREATION_TIME' => $operationContext->shouldPreserveCreationTime(),
			// Legacy hands over the normalized user, which its own write-back always leaves in the options.
			'CURRENT_USER' => $operationContext->getCurrentUserId(),
		]);

		return new Result();
	}

	/**
	 * An activity whose timeline could not be told is stored all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * The activity in the legacy shape the timeline reads it in. See the note of the class on the three rules.
	 *
	 * @return array<string, mixed>
	 */
	private static function legacyFieldsOf(PipelineContext $context, int $activityId): array
	{
		$fields = self::coreColumnsOf($context->getActivity());

		foreach ($context->getTransit()->getValues() as $column => $value)
		{
			if ((string)$column === DeadlineSentinelGuard::COLUMN)
			{
				continue;
			}

			$fields[(string)$column] = self::legacyValueOf($value);
		}

		foreach ($context->getPayload()->getValues() as $field => $value)
		{
			$fields[ActivityFieldMap::getPayloadColumn((string)$field)] = $value;
		}

		$fields['ID'] = $activityId;

		$deadline = self::deadlineOf($context);
		if ($deadline !== null)
		{
			$fields[DeadlineSentinelGuard::COLUMN] = $deadline;
		}

		return array_merge($fields, self::valuesBesideTheColumnsOf($context));
	}

	/**
	 * The columns the typed model holds a value for. A field nobody assigned is a key legacy does not have
	 * either, and that is what keeps the columns the repository derives out of the array.
	 *
	 * @return array<string, mixed>
	 */
	private static function coreColumnsOf(Activity $activity): array
	{
		$columns = [];
		foreach (ActivityFieldMap::getCoreColumns() as $column)
		{
			$value = self::fieldValueOf($activity, (string)ActivityFieldMap::getFieldName($column));
			if ($value === null)
			{
				continue;
			}

			$columns[$column] = self::legacyValueOf(ActivityFieldMap::toColumnValue($column, $value));
		}

		return $columns;
	}

	/**
	 * What legacy keeps in the same array without any column of the table holding it: the marker of an incoming
	 * channel, the reminders the caller named and the moment the counter of the activity turns light at, which the
	 * step filling the counter left in the run. The factory of the scheduled item looks the last two up in storage
	 * where they are missing, so handing them over is what keeps a push from re-reading them.
	 *
	 * @return array<string, mixed>
	 */
	private static function valuesBesideTheColumnsOf(PipelineContext $context): array
	{
		$values = [];
		$activity = $context->getActivity();

		if ($activity->isIncomingChannel() !== null)
		{
			$values[self::COLUMN_IS_INCOMING_CHANNEL] = $activity->isIncomingChannel() === true ? 'Y' : 'N';
		}

		if ($activity->getPingOffsets() !== null)
		{
			$values[self::COLUMN_PING_OFFSETS] = $activity->getPingOffsets();
		}

		if ($context->hasRunValue(PipelineContext::RUN_VALUE_LIGHT_COUNTER_AT))
		{
			$values[self::COLUMN_LIGHT_COUNTER_AT] = $context->getRunValue(PipelineContext::RUN_VALUE_LIGHT_COUNTER_AT);
		}

		return $values;
	}

	/**
	 * The deadline of the operation as the text of the site, or null where the run carries none - the guarded read
	 * of a step of the tail.
	 */
	private static function deadlineOf(PipelineContext $context): ?string
	{
		if (!DeadlineSentinelGuard::isGivenToTheRun($context))
		{
			return null;
		}

		$deadline = DeadlineSentinelGuard::readFrom($context->getTransit());

		return $deadline === null ? null : (string)$deadline;
	}

	/**
	 * The value in the shape legacy carries rather than the shape the ORM writes: a flag is a letter and a moment
	 * is the text of the site.
	 */
	private static function legacyValueOf(mixed $value): mixed
	{
		if (is_bool($value))
		{
			return $value ? 'Y' : 'N';
		}

		return $value instanceof DateTime ? (string)$value : $value;
	}

	private static function fieldValueOf(Activity $activity, string $fieldName): mixed
	{
		return match ($fieldName)
		{
			Activity::id => $activity->getId(),
			Activity::providerId => $activity->getProviderId(),
			Activity::providerTypeId => $activity->getProviderTypeId(),
			Activity::subject => $activity->getSubject(),
			Activity::description => $activity->getDescription(),
			Activity::descriptionType => $activity->getDescriptionType(),
			Activity::isCompleted => $activity->isCompleted(),
			Activity::completionStatus => $activity->getCompletionStatus(),
			Activity::responsibleId => $activity->getResponsibleId(),
			Activity::authorId => $activity->getAuthorId(),
			Activity::editorId => $activity->getEditorId(),
			Activity::isImportant => $activity->isImportant(),
			Activity::direction => $activity->getDirection(),
			Activity::createdTime => $activity->getCreatedTime(),
			Activity::updatedTime => $activity->getUpdatedTime(),
			Activity::startTime => $activity->getStartTime(),
			Activity::endTime => $activity->getEndTime(),
			Activity::deadline => $activity->getDeadline(),
			Activity::parentActivityId => $activity->getParentActivityId(),
			Activity::autocompleteRule => $activity->getAutocompleteRule(),
			default => null,
		};
	}
}
