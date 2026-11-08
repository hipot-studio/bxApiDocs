<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Mapper;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityCommunication;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityStorageElement;
use Bitrix\Crm\V2\Internal\Entity\Activity\ProviderPayload;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityFieldMap;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Pipeline;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\CalendarInputOfTheCaller;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\DeadlineSentinelGuard;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use CCrmDateTimeHelper;

/**
 * The way back: the typed result of an operation of V2 in the terms a caller of `CCrmActivity` reads it in - an
 * identifier or a refusal, a list of messages, and the values the entry point wrote back into the field array of
 * its caller.
 *
 * IT ANSWERS AND CHANGES NOTHING. Registering an error is a side effect of the legacy accumulator and writing the
 * field array is a mutation of an argument of the caller; both belong to the facade, which is the only place that
 * may perform them. This mapper only says what to register, whether the accumulator was emptied on the way
 * ({@see self::hasCheckedTheFields()}) and what to write, so that the same answers can be asserted without a
 * portal-wide accumulator taking part.
 *
 * THE CODES OF THE ERRORS DO NOT COME OUT. Legacy has messages and no codes at all, and the typed codes of V2 are
 * its own business (item A6 of LBS-1): {@see self::errorMessagesOf()} hands over the texts in the order the steps
 * produced them, which is the order legacy registers them in.
 *
 * THE WHOLE OF SECTION 11 IS ANSWERED, AND OUT OF FOUR CARRIERS, NOT ONE. A legacy add leaves in the field array of
 * its caller far more than the columns of its typed model: the derived and cast columns of the owner, every default
 * of the validation, the settings and the params of the provider as arrays, the identifier of a calendar event, the
 * moment the counter of the activity turns light at, the refusal of the record of the activity stream. Those live in
 * the storage columns of the run, in its payload and among its values, so this mapper is given the state of the
 * whole run ({@see Pipeline::RESULT_CONTEXT}) and reads each of them where it really is. A result carrying no such
 * state - which is what a caller assembling one by hand produces - is answered by the activity alone.
 *
 * REMOVING A KEY IS A SECOND ANSWER, BECAUSE MERGING CANNOT REMOVE ONE. The calendar block of legacy takes five keys
 * out of the field array as it reads them (item G3), and the facade merges what this mapper answers into the array of
 * its caller - so the names to take out are answered separately ({@see self::removedCallerKeysOf()}) and the facade
 * performs both.
 *
 * AN UPDATE ANSWERS NOTHING AT ALL, and that is not an omission of this class: legacy takes the fields of an update
 * by value, so no caller of it has ever seen a normalized field. The facade never asks.
 *
 * @internal
 */
final class LegacyOutputMapper
{
	private const KEY_ID = 'ID';
	private const KEY_BINDINGS = 'BINDINGS';
	private const KEY_COMMUNICATIONS = 'COMMUNICATIONS';
	private const KEY_STORAGE_ELEMENT_IDS = 'STORAGE_ELEMENT_IDS';
	private const KEY_PING_OFFSETS = 'PING_OFFSETS';
	private const KEY_IS_INCOMING_CHANNEL = 'IS_INCOMING_CHANNEL';
	private const KEY_LIGHT_COUNTER_AT = 'LIGHT_COUNTER_AT';
	private const KEY_ERROR = 'ERROR';
	private const KEY_LOCATION = 'LOCATION';

	private const COLUMN_OWNER_ID = 'OWNER_ID';
	private const COLUMN_OWNER_TYPE_ID = 'OWNER_TYPE_ID';

	/**
	 * Columns the validation of legacy takes OUT of the field array, so the caller of a successful add has neither of
	 * them left: the two audit stamps, which travel on as raw literals of the database instead (:2121-2135, :2283-2292).
	 */
	private const COLUMN_CREATED = 'CREATED';
	private const COLUMN_LAST_UPDATED = 'LAST_UPDATED';

	private const COLUMNS_THE_VALIDATION_STRIPS = [self::COLUMN_CREATED, self::COLUMN_LAST_UPDATED];

	/**
	 * The deadline is the one column with an answer in two carriers and a rule of its own. The model treats it as
	 * derived and read-only, so it never answers about it here; the column of the transit does, and only where a
	 * deadline was really derived: an activity that has none carries the max-date sentinel there, and legacy then has
	 * no `DEADLINE` key at all - it writes the same sentinel through a raw channel of its own (:2371-2383). An add
	 * that derived one from the start time does leave the key behind (:2355-2360), which is what this answers.
	 */
	private const COLUMN_DEADLINE = 'DEADLINE';

	/**
	 * Storage columns the legacy ENTRY POINT fills in whatever the carriers of the run hold, with the value each is
	 * filled with. There is one: the chain of an activity nobody gave a parent, which legacy sets to zero beside the
	 * parent itself (:152-153) while the step of V2 deriving a chain does not run for such an activity at all.
	 */
	private const COLUMNS_THE_ENTRY_POINT_DEFAULTS = ['THREAD_ID' => 0];

	/**
	 * Columns the validation of legacy always LEAVES in the field array, whether the caller named them or not
	 * (section 4.2 of LBS-1): the answer of the field map to a value nobody assigned is the default of each, which
	 * is the very value legacy writes there.
	 */
	private const COLUMNS_THE_VALIDATION_DEFAULTS = [
		'COMPLETED',
		'STATUS',
		'PRIORITY',
		'DIRECTION',
		'DESCRIPTION_TYPE',
		'PARENT_ID',
	];

	/**
	 * The activity the run left behind, or null when the operation was refused before anything was written.
	 */
	public static function activityOf(Result $result): ?Activity
	{
		if (!$result->isSuccess())
		{
			return null;
		}

		$activity = $result->getData()[Pipeline::RESULT_ACTIVITY] ?? null;

		return $activity instanceof Activity ? $activity : null;
	}

	/**
	 * The identifier of the stored activity, or null when the operation stored none. An add answers with it, and
	 * a run that was refused answers with nothing - which is the plain false of legacy.
	 */
	public static function activityIdOf(Result $result): ?int
	{
		$id = self::activityOf($result)?->getId();

		return ($id ?? 0) > 0 ? $id : null;
	}

	/**
	 * The messages to register in the accumulator of legacy, in the order the operation produced them.
	 *
	 * @return string[]
	 */
	public static function errorMessagesOf(Result $result): array
	{
		return array_values(array_filter(
			array_map(static fn(mixed $message): string => (string)$message, $result->getErrorMessages()),
			static fn(string $message): bool => $message !== '',
		));
	}

	/**
	 * Whether the operation got as far as the check of its fields, which is the one point legacy empties its
	 * accumulator of errors at (item A5 of LBS-1): an operation refused above the check - an update or a completion
	 * over an activity that is not there, a completion of an activity already in the state asked for - leaves the
	 * messages of an earlier failure where they were.
	 *
	 * A result nobody built out of a run answers false: it carries no state to read the fact off, and an
	 * accumulator emptied on such an answer would be emptied by a caller assembling a result by hand.
	 */
	public static function hasCheckedTheFields(Result $result): bool
	{
		return self::contextOf($result)?->hasRunValue(PipelineContext::RUN_VALUE_FIELDS_CHECKED) === true;
	}

	/**
	 * The values the legacy entry point writes back into the field array of its caller - the whole of section 11 of
	 * LBS-1, in the legacy shape of each and out of the carrier each of them really lives in.
	 *
	 * A run that stored nothing writes nothing back, which is what legacy does when it refuses an operation.
	 *
	 * @return array<string, mixed>
	 */
	public static function callerFieldsOf(Result $result): array
	{
		$activity = self::activityOf($result);
		if ($activity === null || ($activity->getId() ?? 0) <= 0)
		{
			return [];
		}

		$context = self::contextOf($result);
		$actingUserId = $context?->getOperationContext()->getCurrentUserId() ?? 0;

		return array_merge(
			self::coreColumnsOf($activity, $actingUserId),
			self::storageColumnsOf($context, $actingUserId),
			self::serializedColumnsOf($context),
			self::collectionsOf($activity),
			self::valuesOfTheRun($context),
			[self::KEY_ID => (int)$activity->getId()],
		);
	}

	/**
	 * The keys the legacy entry point takes OUT of the field array of its caller, of which there are two groups.
	 *
	 * THE VALIDATION TAKES ITS OWN THREE OUT, and it does so in the array of the caller because it works on that very
	 * array by reference: the deadline no caller may write (:2007), and the two audit stamps it replaces with raw
	 * literals of its own (:2121, :2288). The time of the creation is the one exception - an operation that asked for
	 * it to be preserved keeps the key (:2122-2125).
	 *
	 * THE CALENDAR BLOCK TAKES FIVE MORE, as it reads them (item G3, section 13 item 7 of LBS-1), and only where it
	 * really ran.
	 *
	 * ONLY AN ADD HAS AN ARRAY TO CHANGE. An update takes its fields by value, so nothing of this reaches its caller -
	 * which is why the facade asks this of an add alone.
	 *
	 * @return string[]
	 */
	public static function removedCallerKeysOf(Result $result): array
	{
		$context = self::contextOf($result);
		if ($context === null)
		{
			return [];
		}

		$removed = [self::COLUMN_DEADLINE, self::COLUMN_LAST_UPDATED];
		if (!$context->getOperationContext()->shouldPreserveCreationTime())
		{
			$removed[] = self::COLUMN_CREATED;
		}

		if ($context->hasRunValue(PipelineContext::RUN_VALUE_CALENDAR_INPUTS_CONSUMED))
		{
			$removed = array_merge($removed, CalendarInputOfTheCaller::legacyKeys(), [self::KEY_LOCATION]);
		}

		return $removed;
	}

	/**
	 * The state of the whole run, or null for a result nobody built out of one.
	 */
	private static function contextOf(Result $result): ?PipelineContext
	{
		$context = $result->getData()[Pipeline::RESULT_CONTEXT] ?? null;

		return $context instanceof PipelineContext ? $context : null;
	}

	/**
	 * The columns of the typed model, in the shape legacy carries them.
	 *
	 * THREE ANSWERS AND NOT ONE. A column nobody assigned is a key legacy does not have either, which is what keeps
	 * the columns the storage derives out of the array; a column the validation of legacy always fills is there
	 * whether it was assigned or not, with the same default; and the three columns the validation strips are never
	 * there at all.
	 *
	 * @return array<string, mixed>
	 */
	private static function coreColumnsOf(Activity $activity, int $actingUserId): array
	{
		$columns = [];
		foreach (ActivityFieldMap::getCoreColumns() as $column)
		{
			if (in_array($column, self::COLUMNS_THE_VALIDATION_STRIPS, true) || $column === self::COLUMN_DEADLINE)
			{
				continue;
			}

			$value = self::fieldValueOf($activity, (string)ActivityFieldMap::getFieldName($column));
			if ($value === null && !in_array($column, self::COLUMNS_THE_VALIDATION_DEFAULTS, true))
			{
				continue;
			}

			$columns[$column] = self::legacyValueOf(
				ActivityFieldMap::toColumnValue($column, $value),
				$actingUserId,
			);
		}

		return $columns;
	}

	/**
	 * The storage columns of the run - the ones the typed model does not hold, or holds in a poorer form. They are
	 * what most of section 11 is made of: the owner of the activity, the defaults of the notification, of the
	 * associated entity and of the storage of the files, the chain of the thread, the graded priority, the handleable
	 * flag and the identifier of a calendar event the run created.
	 *
	 * THE OWNER IS CAST. Legacy casts the two columns of the owner to integers in the entry point itself (:183-184),
	 * so a caller that named them as text reads them back as numbers. The deadline follows the rule stated on
	 * {@see self::COLUMN_DEADLINE}.
	 *
	 * @return array<string, mixed>
	 */
	private static function storageColumnsOf(?PipelineContext $context, int $actingUserId): array
	{
		if ($context === null)
		{
			return [];
		}

		$columns = self::COLUMNS_THE_ENTRY_POINT_DEFAULTS;
		foreach ($context->getTransit()->getValues() as $column => $value)
		{
			if (in_array($column, self::COLUMNS_THE_VALIDATION_STRIPS, true))
			{
				continue;
			}

			if ($column === self::COLUMN_DEADLINE && DeadlineSentinelGuard::isSentinel($value))
			{
				continue;
			}

			$columns[$column] = match ($column)
			{
				self::COLUMN_OWNER_ID, self::COLUMN_OWNER_TYPE_ID => (int)$value,
				default => self::legacyValueOf($value, $actingUserId),
			};
		}

		return $columns;
	}

	/**
	 * The settings and the params of the provider, as ARRAYS and always: legacy serializes them for the write and
	 * unserializes them back afterwards, so a caller that passed neither reads two empty arrays (:244-245). The data
	 * of the provider is not among them - legacy neither serializes nor restores that column, so the value of the
	 * caller stays its own.
	 *
	 * @return array<string, mixed>
	 */
	private static function serializedColumnsOf(?PipelineContext $context): array
	{
		if ($context === null)
		{
			return [];
		}

		$payload = $context->getPayload();

		$columns = [];
		foreach ([ProviderPayload::settings, ProviderPayload::providerParams] as $field)
		{
			$columns[ActivityFieldMap::getPayloadColumn($field)] = $payload->has($field)
				? $payload->get($field)
				: []
			;
		}

		return $columns;
	}

	/**
	 * The values of the run that legacy keeps in the field array of its caller: whatever the check of the fields of
	 * the provider added and no carrier of V2 holds, the moment the counter of the activity turns light at - which
	 * legacy writes there whatever it computed (:316) - and the refusal a record of the activity stream reported,
	 * where one did.
	 *
	 * @return array<string, mixed>
	 */
	private static function valuesOfTheRun(?PipelineContext $context): array
	{
		if ($context === null)
		{
			return [];
		}

		$values = $context->hasRunValue(PipelineContext::RUN_VALUE_UNCARRIED_PROVIDER_FIELDS)
			? (array)$context->getRunValue(PipelineContext::RUN_VALUE_UNCARRIED_PROVIDER_FIELDS)
			: []
		;

		if ($context->hasRunValue(PipelineContext::RUN_VALUE_LIGHT_COUNTER_AT))
		{
			$values[self::KEY_LIGHT_COUNTER_AT] = $context->getRunValue(
				PipelineContext::RUN_VALUE_LIGHT_COUNTER_AT,
			);
		}

		if ($context->hasRunValue(PipelineContext::RUN_VALUE_LIVEFEED_ERROR))
		{
			$values[self::KEY_ERROR] = $context->getRunValue(PipelineContext::RUN_VALUE_LIVEFEED_ERROR);
		}

		return $values;
	}

	/**
	 * The collections legacy keeps in the same array as the columns, each in its legacy shape: the bindings and
	 * the communications as arrays of rows, the files as the serialized list of their identifiers, which is the
	 * shape legacy hands back (crm_activity.php:409-412).
	 *
	 * @return array<string, mixed>
	 */
	private static function collectionsOf(Activity $activity): array
	{
		$collections = [];

		$bindings = $activity->getBindings();
		if ($bindings !== null)
		{
			$collections[self::KEY_BINDINGS] = array_map(
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
			$collections[self::KEY_COMMUNICATIONS] = array_map(
				static fn(ActivityCommunication $communication): array => [
					'ENTITY_TYPE_ID' => $communication->getEntityType()->getId(),
					'ENTITY_ID' => $communication->getEntityId(),
					'TYPE' => $communication->getType(),
					'VALUE' => $communication->getValue(),
				],
				$communications,
			);
		}

		// The set of the files is answered even where the operation named none, because legacy answers the same way:
		// it normalizes whatever it found under the key - an absent one included, which normalizes to an empty list -
		// and puts the normalized set back (:118-125, :418-421).
		$files = $activity->getFiles() ?? [];
		$collections[self::KEY_STORAGE_ELEMENT_IDS] = array_map(
			static fn(ActivityStorageElement $element): int => $element->getElementId(),
			$files,
		);

		$offsets = $activity->getPingOffsets();
		if ($offsets !== null)
		{
			$collections[self::KEY_PING_OFFSETS] = $offsets;
		}

		if ($activity->isIncomingChannel() !== null)
		{
			$collections[self::KEY_IS_INCOMING_CHANNEL] = $activity->isIncomingChannel() === true ? 'Y' : 'N';
		}

		return $collections;
	}

	/**
	 * The value in the shape legacy carries rather than the shape the storage writes: a flag is a letter and a
	 * moment is the text of the site.
	 *
	 * A MOMENT COMES BACK IN THE TIME OF THE ACTING USER, because that is the time the field array of legacy keeps
	 * (item B9 of LBS-1): the entry point converts its values into the time of the server for the write alone and
	 * leaves the array of its caller in the time that caller named. The typed model holds the absolute moment, so the
	 * conversion back is the one the boundary of the input performed, in reverse.
	 */
	private static function legacyValueOf(mixed $value, int $actingUserId = 0): mixed
	{
		if (is_bool($value))
		{
			return $value ? 'Y' : 'N';
		}

		return $value instanceof DateTime
			? (string)CCrmDateTimeHelper::getUserTime($value, $actingUserId)
			: $value
		;
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
