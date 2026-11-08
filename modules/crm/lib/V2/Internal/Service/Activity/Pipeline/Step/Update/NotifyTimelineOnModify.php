<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\Timeline\ActivityController;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Internal\Entity\Activity\ProviderPayload;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityFieldMap;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepository;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepositoryInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\DeadlineSentinelGuard;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 * Tells the timeline of the bound objects that the activity has changed: row 41 of the owner table STEP-UPDATE
 * (crm_activity.php:1106-1125), checklist item C10.
 *
 * WHAT THE TIMELINE DOES WITH IT, and none of it is decided here: an activity that has just been completed gets a
 * record of the history or is taken off the scheduled stream, one that has been reopened gets a record of the renewal,
 * and an activity being moved from one object to another gets the message of that move. The step hands both states
 * over exactly where legacy does - below the queue of the reminders, above the record of the activity stream - and
 * the timeline decides by the type and the provider of the activity.
 *
 * THE ROW IS UNCONDITIONAL AND IT IS NOT THE RECORD OF THE ACTIVITY STREAM. Nothing stands between the two in legacy,
 * but their gates are different - the timeline is told about every update while a record of the stream is written only
 * where the caller asked for one - and so are their owners. Anchoring the layer of the stream over this call would
 * make the row disappear (the key constraint of `[P5.T4]`).
 *
 * THE MOMENT THE COUNTER TURNS LIGHT AT COMES OFF THE RUN AND NEVER OUT OF STORAGE. Row 32 recomputed it and left it
 * in {@see PipelineContext::RUN_VALUE_LIGHT_COUNTER_AT}, and null is a full answer there - it is what a completed
 * activity has. Legacy fills the same key in from the storage of the moments where its array does not carry it
 * (:1107-1111), and in the pipeline of an update that fill-in IS UNREACHABLE: the row recomputing the counter writes
 * the key on every update, the null included (:883). Reproducing it here would be a second reading of one fact, and
 * the reader below - the factory of the item of the scheduled stream - fills the value in itself anyway where nobody
 * handed one over.
 *
 * THE ONLY READER OF THE MOMENT OF CREATION THE CALLER NAMED, and the second reader of the map of the move of the
 * bindings (row 34 is the first). Both travel in `ADDITIONAL_PARAMS` as legacy assembles them (:1121-1124): the
 * moment sorts a call that closes itself, and the map produces the two messages about the move. The map goes over as
 * it was given - the timeline needs BOTH halves of it to be identifiers of an item and passes over anything else.
 *
 * BOTH STATES GO OVER IN THE LEGACY SHAPE, and the shape of an update is the richer one: the columns of the transit
 * beat the fields of the model, the serialized three travel UNSERIALIZED - which is the shape a fetched row has and
 * the opposite of the shape the subscribers see - the deadline is the one the column really carries, the sentinel
 * included, and the bindings travel inside the array as well, because legacy assigns them into both snapshots
 * (:864-865) before this call.
 *
 * THE SERIALIZED FIELDS OF THE CURRENT STATE ARE READ OFF THE ROW. The run carries the ones the OPERATION named,
 * which on an update naming no settings is nothing at all, while the timeline decides real things by them: the record
 * of a missed call is dated by the moment of creation of the activity, and the item of the scheduled stream is built
 * out of the settings. So the state of the row is read once more, through the boundary of the storage - the same
 * second reading row 34 makes, and the same one a payload carried out of row 17 would spare both rows.
 *
 * @internal
 */
final class NotifyTimelineOnModify implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build. The timeline
	 * is told about an activity, so there is nothing to tell.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	/**
	 * The run reached this step without the state the activity was in. That state is one of the two halves of what
	 * this row hands over: the timeline decides which record to write by comparing the completion of both.
	 */
	public const ERROR_PREVIOUS_STATE_NOT_LOADED = 'CRM_ACTIVITY_PREVIOUS_STATE_NOT_LOADED';

	private const COLUMN_LIGHT_COUNTER_AT = 'LIGHT_COUNTER_AT';

	private const COLUMN_IS_INCOMING_CHANNEL = 'IS_INCOMING_CHANNEL';

	private const COLUMN_BINDINGS = 'BINDINGS';

	private const PARAM_CUSTOM_CREATION_TIME = 'CUSTOM_CREATION_TIME';

	private const PARAM_MOVE_BINDINGS_MAP = 'MOVE_BINDINGS_MAP';

	private readonly ActivityController $timeline;

	private readonly ActivityRepositoryInterface $repository;

	/**
	 * @param ActivityController|null $timeline the timeline of the activities.
	 * @param ActivityRepositoryInterface|null $repository the storage boundary of the activities, asked for the
	 *        serialized fields of the row.
	 */
	public function __construct(?ActivityController $timeline = null, ?ActivityRepositoryInterface $repository = null)
	{
		$this->timeline = $timeline ?? ActivityController::getInstance();
		$this->repository = $repository ?? new ActivityRepository();
	}

	public function getName(): string
	{
		return 'NotifyTimelineOnModify';
	}

	/**
	 * Every update tells the timeline. Which record to write, and whether to write one at all, is its own decision.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return true;
	}

	public function execute(PipelineContext $context): Result
	{
		$result = new Result();

		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return $result->addError(new Error('The activity has no id.', self::ERROR_NO_ID));
		}

		if (!$context->hasPreviousState())
		{
			return $result->addError(new Error(
				'The previous state of the activity was not loaded.',
				self::ERROR_PREVIOUS_STATE_NOT_LOADED,
			));
		}

		$operationContext = $context->getOperationContext();
		$moment = $operationContext->getCustomCreationTime();

		$this->timeline->onModify($activityId, [
			'CURRENT_FIELDS' => $this->currentFieldsOf($context, $activityId),
			'CURRENT_BINDINGS' => self::legacyBindingsOf($context->getActivity()),
			'PREVIOUS_FIELDS' => self::legacyFieldsOf(
				$context->getPreviousActivity(),
				$context->getPreviousPayload(),
				$context->getPreviousTransit(),
			),
			// Legacy hands over the normalized user, which its own write-back always leaves in the options.
			'CURRENT_USER' => $operationContext->getCurrentUserId(),
			'ADDITIONAL_PARAMS' => [
				self::PARAM_CUSTOM_CREATION_TIME => $moment === null ? null : (string)$moment,
				self::PARAM_MOVE_BINDINGS_MAP => $operationContext->getMoveBindingsMap(),
			],
		]);

		return $result;
	}

	/**
	 * An activity whose timeline could not be told is written all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * The re-read state in the legacy shape, plus the moment the counter of the activity turns light at - which no
	 * column of the table holds and the run carries.
	 *
	 * @return array<string, mixed>
	 */
	private function currentFieldsOf(PipelineContext $context, int $activityId): array
	{
		$fields = self::legacyFieldsOf(
			$context->getActivity(),
			$this->repository->getProviderPayload($activityId) ?? new ProviderPayload(),
			$context->getTransit(),
		);

		if ($context->hasRunValue(PipelineContext::RUN_VALUE_LIGHT_COUNTER_AT))
		{
			$fields[self::COLUMN_LIGHT_COUNTER_AT] = $context->getRunValue(PipelineContext::RUN_VALUE_LIGHT_COUNTER_AT);
		}

		return $fields;
	}

	/**
	 * One state of the activity in the legacy shape the timeline reads it in. See the note of the class on the shape.
	 *
	 * @return array<string, mixed>
	 */
	private static function legacyFieldsOf(
		Activity $activity,
		ProviderPayload $payload,
		StorageFieldTransit $transit,
	): array
	{
		$fields = self::coreColumnsOf($activity);

		foreach ($transit->getValues() as $column => $value)
		{
			if ((string)$column === DeadlineSentinelGuard::COLUMN)
			{
				continue;
			}

			$fields[(string)$column] = self::legacyValueOf($value);
		}

		foreach ($payload->getValues() as $field => $value)
		{
			$fields[ActivityFieldMap::getPayloadColumn((string)$field)] = $value;
		}

		$fields[DeadlineSentinelGuard::COLUMN] = (string)DeadlineSentinelGuard::columnOf($activity);
		$fields[self::COLUMN_BINDINGS] = self::legacyBindingsOf($activity);

		$isIncomingChannel = $activity->isIncomingChannel();
		if ($isIncomingChannel !== null)
		{
			$fields[self::COLUMN_IS_INCOMING_CHANNEL] = $isIncomingChannel ? 'Y' : 'N';
		}

		return $fields;
	}

	/**
	 * The columns the typed model holds a value for. A field nobody assigned is a key legacy does not have either.
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
	 * The bindings of one state in the legacy shape, the one the timeline builds the identifiers of its objects out
	 * of. A state nobody snapshotted the bindings of has none.
	 *
	 * @return array<int, array<string, int>>
	 */
	private static function legacyBindingsOf(Activity $activity): array
	{
		return array_map(
			static fn(ActivityBinding $binding): array => [
				'OWNER_TYPE_ID' => $binding->getOwnerType()->getId(),
				'OWNER_ID' => $binding->getOwnerId(),
			],
			$activity->getBindings() ?? [],
		);
	}

	/**
	 * The value in the shape legacy carries rather than the shape the ORM writes: a flag is a letter and a moment is
	 * the text of the site.
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
