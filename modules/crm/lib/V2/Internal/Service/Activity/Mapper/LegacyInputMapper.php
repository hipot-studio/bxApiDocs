<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Mapper;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityCommunication;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityStorageElement;
use Bitrix\Crm\V2\Internal\Entity\Activity\ProviderPayload;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityFieldMap;
use Bitrix\Crm\V2\Internal\Service\Activity\ActivityOperationContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\DeadlineSentinelGuard;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\ObjectException;
use Bitrix\Main\Type\DateTime;
use CCrmActivity;
use CCrmActivityPriority;
use CCrmDateTimeHelper;

/**
 * The one projection of the field array of a legacy write entry point into the typed input of an operation of V2
 * (DTO-01 for the columns, DTO-02 for the operation itself). Its callers are the facade over `CCrmActivity` and
 * the domain entity of a to-do, which assembles the same field array and states a source of the call of its own.
 *
 * IT CONVERTS FORMATS AND DECIDES NOTHING. Every rule of the domain - what a missing field defaults to, which
 * value beats which, what is refused - belongs to the pipeline, so nothing here reads one field to decide about
 * another. What it does is translate three legacy conventions into the typed ones: the upper-case names of the
 * columns into the names of the model, the letters of a flag into a boolean, and the text of a moment into a
 * {@see DateTime} of the acting user.
 *
 * THE MAX-DATE SENTINEL IS NO CONVENTION OF THIS MAPPER. It is how the single column DEADLINE says "no deadline",
 * and that column is dropped here (below), so no value reaching this projection carries that meaning.
 *
 * THE INPUT HAS THREE CARRIERS AND THE FIELD MAP SAYS WHICH. A column of the core category becomes a field of
 * {@see Activity}; the three serialized ones become {@see ProviderPayload}; everything the model cannot hold
 * travels in {@see StorageFieldTransit} by its closed list. The priority is in two of them at once, which is the
 * form its owner table prescribes: the transit carries the three legacy grades the column stores, the model the
 * flag the steps read.
 *
 * A DEADLINE OF THE CALLER IS DROPPED HERE, and that is fidelity rather than a shortcut: legacy takes the key out
 * before the check of the fields on an add (crm_activity.php:2007) and on an update (:2126) alike, so no legacy
 * caller has ever written one. The transit refuses to carry one for the same reason.
 *
 * A COLUMN CARRIED AS NULL IS A COLUMN NOBODY ASSIGNED, which is why the transit is filled by
 * `array_key_exists()` rather than by `isset()`: the two statements are told apart further down, and the checks of
 * the pipeline ask the same question legacy asks with `isset()`.
 *
 * TWO GROUPS OF THE FIELD ARRAY BELONG TO NO COLUMN AND STILL HAVE TO ARRIVE: the user fields of the activity
 * (`UF_*`) and the five inputs of the calendar - the section, the codes of the attendees, the flag of a meeting,
 * the user field of the bound objects and the blocks the description of the event is enriched with. Their carrier
 * is the operation context
 * ({@see ActivityOperationContext::getUserFieldValues()}, {@see ActivityOperationContext::getCalendarInput()}) and
 * not one of the three above: the model holds fields of an activity, the payload the serialized columns and the
 * transit a closed list of storage columns, while these are values of the OPERATION - which is what the context
 * carries. Their readers are the step writing the user fields and the step synchronizing the calendar event, and
 * both read them off the operation the same way they read every other thing the caller named.
 *
 * @internal
 */
final class LegacyInputMapper
{
	/**
	 * The collections legacy keeps in the same array as the columns of the row.
	 */
	private const KEY_BINDINGS = 'BINDINGS';
	private const KEY_COMMUNICATIONS = 'COMMUNICATIONS';
	private const KEY_STORAGE_ELEMENT_IDS = 'STORAGE_ELEMENT_IDS';
	private const KEY_PING_OFFSETS = 'PING_OFFSETS';
	private const KEY_IS_INCOMING_CHANNEL = 'IS_INCOMING_CHANNEL';

	/**
	 * The prefix of the user fields of an activity in the field array of legacy.
	 */
	private const PREFIX_USER_FIELD = 'UF_';

	/**
	 * The keys the calendar event of the activity is built out of and nothing else reads, under the names legacy
	 * gives them. The location is absent on purpose: it IS a storage column and travels in the transit, which is
	 * where the step of the calendar consumes it.
	 *
	 * The user field of the bound objects wears the prefix of a user field and is none of the activity: it belongs
	 * to the event of the calendar, and legacy keeps it out of the write of the user fields by having the calendar
	 * block remove the key before that write (section 13 item 7 of LBS-1). Here the same thing is stated once, by
	 * this list taking the key and {@see self::userFieldValuesOf()} leaving it.
	 *
	 * The blocks the description of the event is enriched with are the fifth. The editor of a to-do puts the links,
	 * the files and the meeting room of an activity into that key, and legacy composes them into the text the event
	 * is described with; it is no column of an activity either. Unlike the four above, legacy reads it WITHOUT
	 * taking it out of the array of its caller - see
	 * {@see \Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\CalendarInputOfTheCaller}.
	 */
	private const KEYS_OF_THE_CALENDAR = [
		'SECTION_ID',
		'ATTENDEES_CODES',
		'IS_MEETING',
		'UF_CRM_CAL_EVENT',
		'CALENDAR_ADDITIONAL_DESCRIPTION_DATA',
	];

	/**
	 * The two letters a legacy flag is written with. Most flags of a write path are compared with the first one
	 * strictly - IS_INCOMING_CHANNEL is (crm_activity.php:240, :780, :2480) - so for those anything else, the
	 * text of a zero, of a one, an empty value, is false. The flag of the completion is the one exception and
	 * has a normalization of its own; see {@see self::completionFlagOf()}.
	 */
	private const FLAG_TRUE = 'Y';
	private const FLAG_FALSE = 'N';

	/**
	 * Everything about the operation besides the activity itself, as the arguments of a legacy entry point state
	 * it: the option array through the single projection of it, and the three values legacy takes positionally.
	 *
	 * @param array<string, mixed>|null $fields The field array the entry point received, and NULL for an entry
	 *        point that has none of its own - a delete and a completion, which say what they do with their own
	 *        arguments. The two are told apart because one demand of the check of the fields is about the array
	 *        itself: an update whose array is empty is refused, while an update the completion runs names a
	 *        field without any array taking part
	 *        ({@see ActivityOperationContext::wasCalledWithNoField()}).
	 * @param array<string, mixed> $options The option array the entry point received.
	 * @param bool $checkPermissions The positional argument of the entry point.
	 * @param bool $registerHistoryEvent Likewise.
	 * @param string $entrySource Where the operation comes from, named by the caller. The default is the facade,
	 *        which is what every legacy entry point is; the domain entity of a to-do names the native source
	 *        instead ([P8.T1]) while assembling the same field array of legacy, which is why it projects it
	 *        through this class rather than through a second projection of its own.
	 */
	public static function operationContextOf(
		?array $fields,
		array $options,
		bool $checkPermissions = true,
		bool $registerHistoryEvent = true,
		string $entrySource = ActivityOperationContext::ENTRY_SOURCE_FACADE,
	): ActivityOperationContext
	{
		$givenFields = $fields ?? [];

		return LegacyOptionsMapper::createOperationContext(
			$options,
			self::payloadOf($givenFields),
			self::transitOf($givenFields),
			entrySource: $entrySource,
			registerHistoryEvent: $registerHistoryEvent,
			checkPermissions: $checkPermissions,
			userFieldValues: self::userFieldValuesOf($givenFields),
			calendarInput: self::calendarInputOf($givenFields),
			incorrectMomentColumns: self::incorrectMomentColumnsOf($givenFields),
			calledWithNoField: $fields === [],
		);
	}

	/**
	 * The datetime columns the caller filled with something that is no moment at all, by the very question legacy
	 * asks of them (crm_activity.php:2219-2237): every column its own field map declares as a datetime, a value
	 * that is there and is not empty, and the predicate of the core over the normalized text.
	 *
	 * THE FACT IS RECORDED HERE BECAUSE IT IS LOST BELOW. {@see self::momentOf()} answers "no moment" for a text
	 * that names none and for a text that was never given alike, so by the time the check of the fields runs, an
	 * unparsable START_TIME is indistinguishable from an absent one - and legacy refuses the whole operation over
	 * the first while accepting the second. Which of them it is is a property of the legacy VALUE, so this
	 * projection states it; what follows from it is decided by
	 * {@see \Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\ValidateAndNormalize}, the one step that
	 * refuses.
	 *
	 * THE PREDICATE IS THE LEGACY ONE, not "the mapper failed to parse it". The two do not agree on every input -
	 * a text missing its seconds is normalized by legacy before it is checked - and the accept-or-refuse answer
	 * has to be the same one on every input, so the question is asked exactly as legacy asks it.
	 *
	 * A DEADLINE IS ASKED ABOUT LIKE ANY OTHER COLUMN, even though nothing ever writes one: legacy checks the
	 * moments before it takes the key away (:2356 on an add, :2476 on an update), so a caller naming an
	 * unparsable deadline is refused there and has to be refused here.
	 *
	 * @param array<string, mixed> $fields
	 * @return string[] columns in the order of the field map of legacy
	 */
	public static function incorrectMomentColumnsOf(array $fields): array
	{
		$columns = [];
		foreach (CCrmActivity::getDateTimeFields() as $column)
		{
			if (!isset($fields[$column]) || $fields[$column] == '')
			{
				continue;
			}

			if (!CheckDateTime(CCrmDateTimeHelper::NormalizeDateTime($fields[$column])))
			{
				$columns[] = $column;
			}
		}

		return $columns;
	}

	/**
	 * The values of the user fields of the activity the caller named. Which of the names the portal really declares
	 * is none of this projection's business: legacy hands its whole field array to the manager of the user fields
	 * and lets it pick, so handing over every `UF_` key states the same thing.
	 *
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	public static function userFieldValuesOf(array $fields): array
	{
		$values = [];
		foreach ($fields as $key => $value)
		{
			$key = (string)$key;
			if (str_starts_with($key, self::PREFIX_USER_FIELD) && !self::isKeyOfTheCalendar($key))
			{
				$values[$key] = $value;
			}
		}

		return $values;
	}

	/**
	 * What the caller said about the calendar event of the activity alone, under the legacy names of those keys.
	 * A key the caller did not name is left out: the layer of the calendar tells a value that was given from one
	 * that was not, and a meeting nobody asked for is not the same statement as a meeting refused.
	 *
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	public static function calendarInputOf(array $fields): array
	{
		$input = [];
		foreach (self::KEYS_OF_THE_CALENDAR as $key)
		{
			if (array_key_exists($key, $fields))
			{
				$input[$key] = $fields[$key];
			}
		}

		return $input;
	}

	private static function isKeyOfTheCalendar(string $key): bool
	{
		return in_array($key, self::KEYS_OF_THE_CALENDAR, true);
	}

	/**
	 * The activity the operation writes: the fields the caller named, and only those. A field nobody named is
	 * left unmarked, so a partial update writes exactly what its field array carried.
	 *
	 * @param array<string, mixed> $fields
	 */
	public static function activityOf(array $fields, ActivityOperationContext $operationContext): Activity
	{
		$activity = new Activity();

		self::applyCoreColumns($activity, $fields, $operationContext->getCurrentUserId());
		self::applyCollections($activity, $fields);

		return $activity;
	}

	/**
	 * The serialized fields of the input, in the shape the caller gave them: the storage owns the form they are
	 * written in and a step of the pipeline owns the one rule about their shape.
	 *
	 * A COLUMN CARRIED AS NULL IS A COLUMN NOBODY ASSIGNED HERE TOO, which is why the payload is filled by
	 * `isset()` and not by `array_key_exists()`: every question legacy asks about these three columns is an
	 * `isset()`, and `isset()` of a null answers no. It asks it four times on an add - the settings and the params
	 * before the write (crm_activity.php:138, :143), the shape of the params at the end of the check of the fields
	 * (:2496) and the assembly of the row itself, which passes over a key of null (`is_set()`) - and four more
	 * times on an update (:561, :575, :580 and the same assembly). So `SETTINGS => null` writes no settings there,
	 * `PROVIDER_PARAMS => null` becomes no empty array, `PROVIDER_DATA => null` clears nothing, and the reverse
	 * projection hands the caller back the empty arrays legacy hands back (:255-256).
	 *
	 * THE TRANSIT ANSWERS THE SAME QUESTION DIFFERENTLY, and the difference is real rather than an inconsistency:
	 * it is filled by `array_key_exists()` because several of its columns are only written when the caller asked
	 * for it, and the steps that ask read the two statements apart. Nothing reads them apart here - a payload
	 * field that is there is written - so a null of this kind has no way to mean anything but "not given".
	 *
	 * @param array<string, mixed> $fields
	 */
	public static function payloadOf(array $fields): ProviderPayload
	{
		$payload = new ProviderPayload();
		foreach (ProviderPayload::getSupportedFields() as $field)
		{
			$column = ActivityFieldMap::getPayloadColumn($field);
			if (isset($fields[$column]))
			{
				$payload->set($field, $fields[$column]);
			}
		}

		return $payload;
	}

	/**
	 * The storage columns of the input the typed model does not hold, by the closed list of the transit.
	 *
	 * @param array<string, mixed> $fields
	 */
	public static function transitOf(array $fields): StorageFieldTransit
	{
		$transit = new StorageFieldTransit();
		foreach (StorageFieldTransit::getSupportedFields() as $column)
		{
			if ($column === DeadlineSentinelGuard::COLUMN || !array_key_exists($column, $fields))
			{
				continue;
			}

			$transit->set($column, $fields[$column]);
		}

		return $transit;
	}

	//region the columns of the core

	/**
	 * @param array<string, mixed> $fields
	 */
	private static function applyCoreColumns(Activity $activity, array $fields, int $actingUserId): void
	{
		foreach (ActivityFieldMap::getCoreColumns() as $column)
		{
			// A column carried as null is a column nobody assigned: that is how the checks of the fields read it
			// (`isset()`) and how the legacy write itself reads it (`is_set()` skips such a key), so a field of the
			// model stays untouched by one. Clearing a field is done with the empty value of legacy - an empty text,
			// an empty moment, a zero - exactly as the reverse projection of a provider expresses it.
			if (($fields[$column] ?? null) === null)
			{
				continue;
			}

			self::applyCoreColumn($activity, $column, $fields[$column], $actingUserId);
		}
	}

	/**
	 * One column of the core in the field of the model that stores it. The identifier and the audit stamps of a
	 * row are no input of an operation: the identifier travels beside the field array and the stamps belong to
	 * the storage - except for the moment of the creation, which an operation may ask to be preserved and which
	 * therefore arrives through the hydration path the read-only fields have.
	 */
	private static function applyCoreColumn(
		Activity $activity,
		string $column,
		mixed $value,
		int $actingUserId,
	): void
	{
		match ($column)
		{
			'PROVIDER_ID' => $activity->setProviderId((string)$value),
			'PROVIDER_TYPE_ID' => $activity->setProviderTypeId((string)$value),
			'SUBJECT' => $activity->setSubject((string)$value),
			'DESCRIPTION' => $activity->setDescription((string)$value),
			'DESCRIPTION_TYPE' => $activity->setDescriptionType(
				ActivityFieldMap::toFieldValue($column, $value),
			),
			'COMPLETED' => $activity->setCompleted(self::completionFlagOf($value)),
			'STATUS' => $activity->setCompletionStatus(ActivityFieldMap::toFieldValue($column, $value)),
			'RESPONSIBLE_ID' => $activity->setResponsibleId((int)$value),
			'AUTHOR_ID' => $activity->setAuthorId((int)$value),
			'EDITOR_ID' => $activity->setEditorId((int)$value),
			'PRIORITY' => $activity->setImportant((int)$value === CCrmActivityPriority::High),
			'DIRECTION' => $activity->setDirection(ActivityFieldMap::toFieldValue($column, $value)),
			'START_TIME' => $activity->setStartTime(self::momentOf($value, $actingUserId)),
			'END_TIME' => $activity->setEndTime(self::momentOf($value, $actingUserId)),
			'PARENT_ID' => $activity->setParentActivityId(ActivityFieldMap::toFieldValue($column, $value)),
			'AUTOCOMPLETE_RULE' => $activity->setAutocompleteRule(
				ActivityFieldMap::toFieldValue($column, $value),
			),
			'CREATED' => $activity->internalSet(
				Activity::createdTime,
				self::momentOf($value, $actingUserId),
			),
			default => null,
		};
	}

	//endregion

	//region the collections beside the columns

	/**
	 * @param array<string, mixed> $fields
	 */
	private static function applyCollections(Activity $activity, array $fields): void
	{
		if (is_array($fields[self::KEY_BINDINGS] ?? null))
		{
			$activity->setBindings(...self::bindingsOf($fields[self::KEY_BINDINGS]));
		}

		if (is_array($fields[self::KEY_COMMUNICATIONS] ?? null))
		{
			$activity->setCommunications(...self::communicationsOf($fields[self::KEY_COMMUNICATIONS]));
		}

		if (is_array($fields[self::KEY_STORAGE_ELEMENT_IDS] ?? null))
		{
			$activity->setFiles(...self::filesOf($fields[self::KEY_STORAGE_ELEMENT_IDS]));
		}

		if (is_array($fields[self::KEY_PING_OFFSETS] ?? null))
		{
			$activity->setPingOffsets(...self::offsetsOf($fields[self::KEY_PING_OFFSETS]));
		}

		if (array_key_exists(self::KEY_IS_INCOMING_CHANNEL, $fields))
		{
			// The marker has no setter: it lives in a table of its own and the pipeline maintains it. Whether
			// the operation named it at all is a question the steps ask, so it arrives as the value it was
			// named with and never as a change of the activity.
			$activity->internalSet(
				Activity::isIncomingChannel,
				self::flagOf($fields[self::KEY_IS_INCOMING_CHANNEL]),
			);
		}
	}

	/**
	 * The bindings of the activity. A row naming no real object is left out: the model of a binding has no way
	 * to express one, and the write path of legacy does not store one either.
	 *
	 * @param array<mixed> $rows
	 * @return ActivityBinding[]
	 */
	private static function bindingsOf(array $rows): array
	{
		$bindings = [];
		foreach ($rows as $row)
		{
			if (!is_array($row))
			{
				continue;
			}

			$ownerTypeId = (int)($row['OWNER_TYPE_ID'] ?? 0);
			$ownerId = (int)($row['OWNER_ID'] ?? 0);
			if ($ownerTypeId <= 0 || $ownerId <= 0)
			{
				continue;
			}

			$bindings[] = new ActivityBinding(OwnerType::fromId($ownerTypeId), $ownerId);
		}

		return $bindings;
	}

	/**
	 * THE CHANNEL IS WHAT A ROW IS DROPPED FOR, AND THE ENTITY IS NOT. Legacy stores the given set element
	 * for element and casts the channel and the address alone (mysql/crm_activity.php:317-332), so an address
	 * the caller attributed to no CRM entity is a communication of the activity - the shape outgoing mail
	 * builds whenever no `ADDRESS_SOURCE` was handed over (integration/mailmanager.php:249-253, :292-298).
	 * Only a half-named pair is straightened out, into the absence of an entity: a type without an id and an
	 * id without a type are neither an entity nor the absence of one, and legacy's own readers judge such a
	 * row by that very rule (`PrepareCommunicationInfos`, crm_activity.php:4018-4022).
	 *
	 * @param array<mixed> $rows
	 * @return ActivityCommunication[]
	 */
	private static function communicationsOf(array $rows): array
	{
		$communications = [];
		foreach ($rows as $row)
		{
			if (!is_array($row))
			{
				continue;
			}

			$type = (string)($row['TYPE'] ?? '');
			if ($type === '')
			{
				continue;
			}

			$entityTypeId = (int)($row['ENTITY_TYPE_ID'] ?? 0);
			$entityId = (int)($row['ENTITY_ID'] ?? 0);
			if ($entityTypeId <= 0 || $entityId <= 0)
			{
				$entityTypeId = OwnerType::UNDEFINED;
				$entityId = 0;
			}

			$communications[] = new ActivityCommunication(
				$type,
				(string)($row['VALUE'] ?? ''),
				OwnerType::fromId($entityTypeId),
				$entityId,
			);
		}

		return $communications;
	}

	/**
	 * @param array<mixed> $elementIds
	 * @return ActivityStorageElement[]
	 */
	private static function filesOf(array $elementIds): array
	{
		$files = [];
		foreach ($elementIds as $elementId)
		{
			if ((int)$elementId > 0)
			{
				$files[] = new ActivityStorageElement((int)$elementId);
			}
		}

		return $files;
	}

	/**
	 * @param array<mixed> $offsets
	 * @return int[]
	 */
	private static function offsetsOf(array $offsets): array
	{
		return array_map(static fn(mixed $offset): int => (int)$offset, array_values($offsets));
	}

	//endregion

	//region the conventions of a legacy value

	/**
	 * A flag of legacy: the letter it is true as, or a boolean a caller handed over as it is.
	 */
	private static function flagOf(mixed $value): bool
	{
		return is_bool($value) ? $value : $value === self::FLAG_TRUE;
	}

	/**
	 * THE ONE FLAG LEGACY NORMALIZES INSTEAD OF COMPARING. Before the write it upper-cases the value and, where
	 * the result is neither letter, answers by the integer the value names: `mb_strtoupper(strval(...))`, then
	 * `intval(...) > 0` (crm_activity.php:2244-2252 on an add and :2348-2356 on an update - the two spell the
	 * same rule). So 'y' and 'n' state what 'Y' and 'N' state, '1' and 1 state a completed activity, and 'maybe'
	 * - a text naming no number - states an open one.
	 *
	 * This belongs here rather than in the pipeline because it is a convention of the legacy VALUE and not a rule
	 * of the domain: what a completed activity then implies for the status and for the handleable flag is decided
	 * by {@see \Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\ValidateAndNormalize} alone.
	 *
	 * A boolean is answered as it is, which is the same answer the legacy arithmetic gives it: `strval(true)`
	 * is the text of a one and `strval(false)` an empty text, so `intval()` separates them exactly so.
	 */
	private static function completionFlagOf(mixed $value): bool
	{
		if (is_bool($value))
		{
			return $value;
		}

		$letter = mb_strtoupper((string)$value);
		if ($letter === self::FLAG_TRUE || $letter === self::FLAG_FALSE)
		{
			return $letter === self::FLAG_TRUE;
		}

		return (int)$value > 0;
	}

	/**
	 * The moment the value names, or null where it names none: nothing given or an empty value.
	 *
	 * The text is read as a local time of the user the operation acts as, which is the conversion legacy
	 * performs right before the write (`NormalizeDateTimeFields`, :1590-1607). The text is completed first, the
	 * way legacy completes it before it reads it (`NormalizeDateTime`, :2224): a value whose seconds are left out
	 * gets them, so the moment stored through this path is the moment legacy stores.
	 *
	 * A VALUE THAT IS NO MOMENT AT ALL READS AS NONE HERE, and it is refused elsewhere: the fact that the caller
	 * gave one is recorded by {@see self::incorrectMomentColumnsOf()} and turned into the refusal of legacy by the
	 * check of the fields. Refusing it here would be a rule this mapper is not allowed to have, and answering
	 * "no moment" without recording anything is what let such a value through as an absent one.
	 *
	 * THE MAX-DATE SENTINEL IS A MOMENT LIKE ANY OTHER HERE. Its meaning belongs to DEADLINE alone - the one
	 * column {@see ActivityFieldMap} resolves it out of on the way back - and that column never reaches this
	 * method. Legacy normalizes the moments of an activity without ever looking at the year, so a caller naming
	 * the year 9999 in START_TIME, END_TIME or CREATED names that very moment.
	 */
	private static function momentOf(mixed $value, int $actingUserId): ?DateTime
	{
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
			return CCrmDateTimeHelper::createFromUserTime(
				(string)CCrmDateTimeHelper::NormalizeDateTime($value),
				$actingUserId,
			);
		}
		catch (ObjectException)
		{
			return null;
		}
	}

	//endregion
}
