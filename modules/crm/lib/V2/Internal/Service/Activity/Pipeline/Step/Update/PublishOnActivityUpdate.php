<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityCommunication;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityStorageElement;
use Bitrix\Crm\V2\Internal\Entity\Activity\ProviderPayload;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityFieldMap;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepository;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepositoryInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\DeadlineSentinelGuard;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\EventPublisher;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\ThrowableTransparentStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 * Announces the changed activity to the subscribers of the module: row 47 of the owner table STEP-UPDATE
 * (crm_activity.php:1198-1211), row D12 of the checklist and two rows of the canonical block EVENT-01.
 *
 * TWO EVENTS, ONE ROW, AND THE ORDER OF THEM IS THE CONTRACT. The event the REST webhooks of third-party
 * applications are delivered from goes first, and the event warming the cache of the broker of activities goes
 * second; both are published by one call of the single publication point ({@see EventPublisher}), because a step
 * ordering them itself would be a second place the order is stated. Splitting the row in two would put the order
 * into the declaration of the pipeline, where the next reordering would silently change what a webhook sees.
 *
 * THE TWO EVENTS CARRY DIFFERENT THINGS, and that is the whole reason legacy publishes both. The first one hands
 * over WHAT THE OPERATION WROTE - "the identifier and the changed fields", as EVENT-01 puts it - and the second
 * one hands over the WHOLE OF BOTH STATES, the one before the write and the one re-read after it.
 *
 * WHAT THE CHANGED FIELDS ARE MADE OF, and it is the input representation of the operation rather than the state
 * of the run. Past the re-read of row 17 the state of the run is the row and no longer the fields being written,
 * while {@see PipelineContext::getInputActivity()} is exactly the field array legacy carries - the values the
 * caller named, worked by the rows above the write. So the model of the input supplies the core columns, the
 * payload and the transit of the operation supply the rest, and the wrapper of a legacy provider builds the same
 * legacy argument out of the same two carriers - which is right, because legacy hands its provider and its
 * subscribers one and the same array.
 *
 * FOUR COLUMNS COME OFF THE RUN ALL THE SAME, and the list is closed: the pair of the owner, the chain of the
 * thread and the deadline. Legacy derives all four into the very array it publishes - the owner from the set of
 * bindings (:559-580), the chain from the parent (:598-613) and the deadline in the provider part of the check of
 * the fields - and in V2 those rows write into the transit of the run rather than into the input one. Nothing else
 * is taken from there: the columns row 17 filled in are the row and not the operation, and the location and the
 * event of the calendar row 39 leaves behind are answers of the calendar that legacy keeps out of this array on
 * purpose (it writes them into its re-read snapshot instead).
 *
 * THE EMOJI GO OUT ESCAPED, AND THAT IS PARITY RATHER THAN A DEVIATION. An update escapes the two texts for the
 * write and never puts them back, so the model of the input carries the escaped shape - which is exactly what
 * legacy publishes here (item D14, LBS-1 section 15 item 20). The re-read state is the other half of the story:
 * the storage boundary unescapes on the way out, so the two snapshots of the second event carry the characters -
 * and so does legacy, whose own re-read decodes them as well.
 *
 * THE IDENTIFIER IS NOT IN THE CHANGED FIELDS. Legacy takes it out of the array above the write (:616-619) and
 * never puts it back on this path, so a subscriber reads it off the first argument of the event, exactly as the
 * event before the write hands it over.
 *
 * WHAT A SUBSCRIBER CHANGES REACHES NOBODY, and that is not a divergence: legacy publishes this event two steps
 * before returning and reads the array afterwards only to clear a cache by an identifier. The reverse contract of
 * the facade owns whatever a caller is supposed to see (`[P7.T5]`).
 *
 * @internal
 */
final class PublishOnActivityUpdate implements ThrowableTransparentStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build. Both
	 * events are about a stored activity.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	/**
	 * The run reached this step without the state the activity was in. That state is half of the payload of the
	 * second event: the broker compares nothing, but a subscriber told "before" and "current" of one and the same
	 * snapshot would be told the activity did not change.
	 */
	public const ERROR_PREVIOUS_STATE_NOT_LOADED = 'CRM_ACTIVITY_PREVIOUS_STATE_NOT_LOADED';

	/**
	 * Columns the rows above the write derive into the transit of the run and legacy carries in the very array it
	 * publishes here. See the note of the class - nothing else of the transit of the run belongs to this array.
	 */
	private const COLUMNS_DERIVED_ABOVE_THE_WRITE = ['OWNER_ID', 'OWNER_TYPE_ID', 'THREAD_ID', 'DEADLINE'];

	private const COLUMN_BINDINGS = 'BINDINGS';

	private const COLUMN_COMMUNICATIONS = 'COMMUNICATIONS';

	private const COLUMN_STORAGE_ELEMENT_IDS = 'STORAGE_ELEMENT_IDS';

	private const COLUMN_PING_OFFSETS = 'PING_OFFSETS';

	private const COLUMN_IS_INCOMING_CHANNEL = 'IS_INCOMING_CHANNEL';

	private const COLUMN_LIGHT_COUNTER_AT = 'LIGHT_COUNTER_AT';

	private readonly EventPublisher $publisher;

	private readonly ActivityRepositoryInterface $repository;

	/**
	 * @param EventPublisher|null $publisher the single publication point of the events of the domain.
	 * @param ActivityRepositoryInterface|null $repository the storage boundary of the activities, asked for the
	 *        serialized fields the re-read row carries.
	 */
	public function __construct(
		?EventPublisher $publisher = null,
		?ActivityRepositoryInterface $repository = null,
	)
	{
		$this->publisher = $publisher ?? new EventPublisher();
		$this->repository = $repository ?? new ActivityRepository();
	}

	public function getName(): string
	{
		return 'PublishOnActivityUpdate';
	}

	/**
	 * Every update is announced. Legacy has no path around this.
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

		$fields = self::changedFieldsOf($context);

		$this->publisher->publishOnActivityUpdate(
			$activityId,
			$fields,
			self::stateOf(
				$context->getPreviousActivity(),
				$context->getPreviousPayload(),
				$context->getPreviousTransit(),
			),
			$this->currentStateOf($context, $activityId),
		);

		return $result;
	}

	/**
	 * The answers of the subscribers are ignored, so there is nothing here to fail on.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	//region the fields the operation wrote

	/**
	 * @return array<string, mixed> what the operation named, in the shape the subscribers read.
	 */
	private static function changedFieldsOf(PipelineContext $context): array
	{
		$operationContext = $context->getOperationContext();
		$activity = $context->getInputActivity();

		$fields = self::coreColumnsOf($activity, skipId: true);

		foreach ($operationContext->getInputTransit()->getValues() as $column => $value)
		{
			$fields[(string)$column] = self::legacyValueOf($value);
		}

		foreach (self::COLUMNS_DERIVED_ABOVE_THE_WRITE as $column)
		{
			if ($context->getTransit()->has($column))
			{
				$fields[$column] = self::legacyValueOf($context->getTransit()->get($column));
			}
		}

		return array_merge(
			$fields,
			self::writtenBlobsOf($operationContext->getInputPayload()),
			self::collectionsOfTheInput($activity),
		);
	}

	/**
	 * The three columns stored as one blob each, in the shape legacy has them at this point of an update: the
	 * settings and the parameters of the provider UNSERIALIZED, and there whether they were given or not - legacy
	 * unserializes both keys back and defaults each to an empty array (:697-698); the data of the provider as it
	 * was given, and only when it was.
	 *
	 * @return array<string, mixed>
	 */
	private static function writtenBlobsOf(ProviderPayload $payload): array
	{
		$settings = $payload->has(ProviderPayload::settings) ? $payload->get(ProviderPayload::settings) : [];
		$params = $payload->has(ProviderPayload::providerParams)
			? $payload->get(ProviderPayload::providerParams)
			: []
		;

		$columns = [
			ActivityFieldMap::getPayloadColumn(ProviderPayload::settings) => is_array($settings) ? $settings : [],
			ActivityFieldMap::getPayloadColumn(ProviderPayload::providerParams) => $params,
		];

		if ($payload->has(ProviderPayload::providerData))
		{
			$columns[ActivityFieldMap::getPayloadColumn(ProviderPayload::providerData)] =
				$payload->get(ProviderPayload::providerData);
		}

		return $columns;
	}

	/**
	 * The collections legacy keeps in the same array as the columns, each in the shape it has them in at this
	 * point of an update. The files are the one difference from an add: an update serializes the list for the
	 * write and puts the plain one back afterwards (:515-521, :1182-1185), so a subscriber of an update sees the
	 * identifiers and a subscriber of an add sees the serialized text.
	 *
	 * @return array<string, mixed>
	 */
	private static function collectionsOfTheInput(Activity $activity): array
	{
		$collections = [];

		$bindings = $activity->getBindings();
		if ($bindings !== null)
		{
			$collections[self::COLUMN_BINDINGS] = self::legacyBindingsOf($activity);
		}

		$communications = $activity->getCommunications();
		if ($communications !== null)
		{
			$collections[self::COLUMN_COMMUNICATIONS] = array_map(
				static fn(ActivityCommunication $communication): array => [
					'ENTITY_TYPE_ID' => $communication->getEntityType()->getId(),
					'ENTITY_ID' => $communication->getEntityId(),
					'TYPE' => $communication->getType(),
					'VALUE' => $communication->getValue(),
				],
				$communications,
			);
		}

		$files = $activity->getFiles();
		if ($files !== null)
		{
			$collections[self::COLUMN_STORAGE_ELEMENT_IDS] = array_map(
				static fn(ActivityStorageElement $element): int => $element->getElementId(),
				$files,
			);
		}

		$pingOffsets = $activity->getPingOffsets();
		if ($pingOffsets !== null)
		{
			$collections[self::COLUMN_PING_OFFSETS] = $pingOffsets;
		}

		$isIncomingChannel = $activity->isIncomingChannel();
		if ($isIncomingChannel !== null)
		{
			$collections[self::COLUMN_IS_INCOMING_CHANNEL] = $isIncomingChannel ? 'Y' : 'N';
		}

		return $collections;
	}

	//endregion

	//region the two states

	/**
	 * The re-read state in the legacy shape, plus the moment the counter of the activity turns light at - which no
	 * column of the table holds and the run carries (:883). The serialized fields are the ones of the ROW: legacy
	 * reads its snapshot back in full, while the run carries only the ones the operation named.
	 *
	 * @return array<string, mixed>
	 */
	private function currentStateOf(PipelineContext $context, int $activityId): array
	{
		$fields = self::stateOf(
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
	 * One whole state of the activity in the shape a fetched row has it: the core columns of the model, the
	 * storage columns beside them, the three blobs unserialized, the deadline the column really carries - the
	 * sentinel included - and the two things legacy assigns into both of its snapshots by hand, the set of
	 * bindings (:864-865) and the marker of the incoming channel (:762-777).
	 *
	 * @return array<string, mixed>
	 */
	private static function stateOf(
		Activity $activity,
		ProviderPayload $payload,
		StorageFieldTransit $transit,
	): array
	{
		$fields = self::coreColumnsOf($activity, skipId: false);

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

	//endregion

	//region the legacy shape

	/**
	 * The columns the typed model holds a value for. A field nobody assigned is a key legacy does not have either.
	 *
	 * @return array<string, mixed>
	 */
	private static function coreColumnsOf(Activity $activity, bool $skipId): array
	{
		$columns = [];
		foreach (ActivityFieldMap::getCoreColumns() as $column)
		{
			if ($skipId && $column === 'ID')
			{
				continue;
			}

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
	 * @return array<int, array<string, int>> the bindings of the state in the legacy shape; a state nobody
	 *         snapshotted the bindings of has none.
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

	//endregion
}
