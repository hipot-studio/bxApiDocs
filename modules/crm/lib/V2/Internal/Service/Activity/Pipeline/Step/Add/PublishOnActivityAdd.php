<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityCommunication;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityStorageElement;
use Bitrix\Crm\V2\Internal\Entity\Activity\ProviderPayload;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityFieldMap;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\DeadlineSentinelGuard;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\EventPublisher;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\ThrowableTransparentStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 * Announces the created activity to the subscribers of the module: step 37 of the owner table of an add
 * (crm_activity.php:466-470), the last step of the operation and the last of the tail of item C11.
 *
 * THIS IS THE EVENT THIRD-PARTY APPLICATIONS SEE. The REST webhooks are delivered from it, and so is the
 * invalidation of the cache of the broker of activities and the reaction of the e-mail integration. It stands below
 * every effect of the add on purpose: what a subscriber reads is the activity with its bindings, its communications,
 * its statistics and its search index already in place, and with its providers already told. Anything moved above it
 * would deliver a webhook about a half-written activity.
 *
 * THERE IS NO VETO AND NO ANSWER, so the step never fails and never aborts - the same as the event before the write
 * ({@see EventPublisher}).
 *
 * THE SHAPE IS THE LEGACY ONE AND IT IS THE SHAPE OF WHAT WAS WRITTEN, which is where it differs from the event
 * before the write:
 * - the settings and the parameters of the provider travel UNSERIALIZED, and both keys are always there - an empty
 *   array where nobody gave them (:233-235, section 11 of LBS-1). The event before the write sees them serialized,
 *   and the inversion is legacy's own;
 * - the identifier of the activity is in the array as well as in the argument beside it (:233);
 * - the subject and the description are the texts of the caller, not the escaped ones: the step that wrote the row
 *   put them back;
 * - the bindings are the effective set, with the binding of the owner appended to it, because that is the set the
 *   write of the bindings left in the model;
 * - the columns of the transit beat the fields of the model, as they do at the write, and the location is not among
 *   them any more - the calendar step consumed it (item G3);
 * - the deadline is read through the guard, so an activity nobody gave one is handed no key instead of the sentinel;
 * - the moment the counter turns light at comes out of the values of the run, where the counter step left it.
 *
 * WHAT THE ARRAY DOES NOT CARRY, for the same reason the event before the write does not: the columns whose value
 * the storage layer derives on its own - the priority, the handleable flag, the direction, the timestamps of the
 * audit. Their author is the repository and a second author would be a second answer.
 *
 * WHAT A SUBSCRIBER CHANGES REACHES NOBODY, and that is not a divergence of the row: legacy publishes this event
 * one line before returning, so a mutation of it never reaches storage either - it reaches the array of the caller.
 * Handing that on is the business of the reverse contract of the facade (`[P7.T5]`), which is also the owner of the
 * `ERROR` key of the activity stream; there is no reader of it inside the pipeline.
 *
 * @internal
 */
final class PublishOnActivityAdd implements ThrowableTransparentStepInterface
{
	/**
	 * There is no created activity to announce before the row exists. Legacy cannot reach this state: it interrupts
	 * an add on a failed insert.
	 */
	public const ERROR_NOT_STORED = 'CRM_ACTIVITY_ON_ACTIVITY_ADD_WITHOUT_ACTIVITY';

	private const COLUMN_BINDINGS = 'BINDINGS';

	private const COLUMN_COMMUNICATIONS = 'COMMUNICATIONS';

	private const COLUMN_STORAGE_ELEMENT_IDS = 'STORAGE_ELEMENT_IDS';

	private const COLUMN_PING_OFFSETS = 'PING_OFFSETS';

	private const COLUMN_IS_INCOMING_CHANNEL = 'IS_INCOMING_CHANNEL';

	private const COLUMN_LIGHT_COUNTER_AT = 'LIGHT_COUNTER_AT';

	private readonly EventPublisher $publisher;

	/**
	 * @param EventPublisher|null $publisher the single publication point of the events of the domain; the one seam
	 *        of the step.
	 */
	public function __construct(?EventPublisher $publisher = null)
	{
		$this->publisher = $publisher ?? new EventPublisher();
	}

	public function getName(): string
	{
		return 'PublishOnActivityAdd';
	}

	/**
	 * Every add is announced. Legacy has no path around this.
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

		$fields = self::legacyFieldsOf($context, $activityId);

		$this->publisher->publishOnActivityAdd($activityId, $fields);

		return new Result();
	}

	/**
	 * The answers of the subscribers are ignored, so there is nothing here to fail on.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	//region the legacy shape

	/**
	 * @return array<string, mixed> the created activity in the shape the subscribers read it in.
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

		$fields['ID'] = $activityId;

		$deadline = self::deadlineOf($context);
		if ($deadline !== null)
		{
			$fields[DeadlineSentinelGuard::COLUMN] = $deadline;
		}

		return array_merge(
			$fields,
			self::storedBlobsOf($context->getPayload()),
			self::collectionsOf($context->getActivity()),
			self::valuesBesideTheColumnsOf($context),
		);
	}

	/**
	 * The columns the typed model holds a value for. A field nobody assigned is a key legacy does not have either,
	 * which is what keeps the columns the repository derives out of the array.
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
	 * The three columns stored as one blob each, in the shape legacy has them at this point of an add: the settings
	 * and the parameters of the provider unserialized, and there whether they were given or not; the data of the
	 * provider as it was given, and only when it was.
	 *
	 * @return array<string, mixed>
	 */
	private static function storedBlobsOf(ProviderPayload $payload): array
	{
		$settings = $payload->has(ProviderPayload::settings) ? $payload->get(ProviderPayload::settings) : [];
		$params = $payload->has(ProviderPayload::providerParams)
			? $payload->get(ProviderPayload::providerParams)
			: [];

		$columns = [
			ActivityFieldMap::getPayloadColumn(ProviderPayload::settings) => is_array($settings) ? $settings : [],
			ActivityFieldMap::getPayloadColumn(ProviderPayload::providerParams) => $params,
		];

		if ($payload->has(ProviderPayload::providerData))
		{
			$column = ActivityFieldMap::getPayloadColumn(ProviderPayload::providerData);
			$columns[$column] = $payload->get(ProviderPayload::providerData);
		}

		return $columns;
	}

	/**
	 * The collections legacy keeps in the same array as the columns, each in its legacy shape: the effective set of
	 * bindings and the communications as arrays of rows, the files as the serialized list of their identifiers,
	 * which is the shape the row was written from and the one legacy never converts back (:114).
	 *
	 * @return array<string, mixed>
	 */
	private static function collectionsOf(Activity $activity): array
	{
		$collections = [];

		$bindings = $activity->getBindings();
		if ($bindings !== null)
		{
			$collections[self::COLUMN_BINDINGS] = array_map(
				static fn(ActivityBinding $binding): array => [
					'OWNER_TYPE_ID' => $binding->getOwnerType()->getId(),
					'OWNER_ID' => $binding->getOwnerId(),
				],
				$bindings,
			);
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
			$collections[self::COLUMN_STORAGE_ELEMENT_IDS] = serialize(array_map(
				static fn(ActivityStorageElement $element): int => $element->getElementId(),
				$files,
			));
		}

		return $collections;
	}

	/**
	 * What legacy keeps in the same array without any column of the table holding it: the marker of an incoming
	 * channel and the reminders as the caller named them - the resolved ones stay in the local variable of legacy
	 * and never reach a subscriber - and the moment the counter of the activity turns light at.
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
	 * The deadline of the operation as the text of the site, or null where the run carries none - the guarded read of
	 * a step of the tail.
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

	//endregion
}
