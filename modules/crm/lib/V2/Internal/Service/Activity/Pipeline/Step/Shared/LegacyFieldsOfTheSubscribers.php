<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityCommunication;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityStorageElement;
use Bitrix\Crm\V2\Internal\Entity\Activity\ProviderPayload;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityFieldMap;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\ObjectException;
use Bitrix\Main\Type\DateTime;
use CCrmDateTimeHelper;

/**
 * The array of column names the subscribers of a write read, and the answer they give back.
 *
 * ONE RULE FOR TWO STEPS. The publication before an add and the publication before an update show the same array
 * and read the same answer off it; a copy of the rule in each step would be the copy that goes stale, and the one
 * value it would go stale on first is the string a subscriber hands to `unserialize()`. It lives beside the other
 * small shared statements of the pipeline for the same reason those do.
 *
 * THE SHAPE IS THE LEGACY ONE, AND THIS BUILDS IT. The publisher publishes and knows nothing of an activity; the
 * subscribers - REST webhooks of third-party applications among them - read an array of upper-case column names.
 * What goes in is everything the run carries: the core columns off the typed model, the storage columns off the
 * transit (the transit wins, as it does at the write), the three serialized columns off the payload, and the
 * collections legacy keeps in the same array. Two values are converted into the shape legacy carries rather than
 * the shape the ORM writes - a completion flag travels as `Y` or `N` and a moment as the text of the site -
 * because that is what a subscriber compares against.
 *
 * WHAT THE ARRAY DOES NOT CARRY, and it is a divergence rather than an oversight - registry row
 * BEFORE-ADD-DEFAULTS of the ADR: the columns whose value the storage layer derives on its own. On an add those
 * are the priority of an activity nobody called important, the direction, the parent, the empty list of files,
 * the thread, the enriched description of the provider and the raw channel of the moments - the deadline and the
 * timestamps of the audit. Legacy has them in its array by this point because its check of the fields fills them
 * in; in V2 the repository derives exactly what legacy derives and a second author of those values would be a
 * second answer about them (see the note of `ValidateAndNormalize`). A subscriber that reads one of them sees a
 * missing key instead of a default. The values derived above the publication - the completion flag, its status
 * and the handleable flag - are in the array, because there the check of the fields is their author in V2 too.
 *
 * WHAT A SUBSCRIBER CHANGES REACHES THE ROW, AND SO DOES WHAT IT TAKES AWAY. The fields travel by reference
 * because the event exists for that ({@see EventPublisher}), and the array that came back is put back into the
 * carriers it was read from - legacy writes the array it got back whole (an add :189-196, an update :675-678),
 * so the three answers a subscriber can give are the same one answer. A value that changed and a key that was
 * added go into the carrier of their column: the transit, the payload, a collection of the model or the field of
 * a core column. A key that is gone leaves that carrier, which is legacy leaving the column out of the write.
 *
 * TWO SHAPES ARE INVERTED HERE, and only two, because the publication created only two: a flag comes back off
 * its letter and a moment off the text, read as the local time of the acting user - which is where legacy reads
 * a moment from as well, right before its own write (`NormalizeDateTimeFields`, :1888-1905).
 *
 * WHAT AN ANSWER CANNOT DO. The columns the storage layer assigns on its own - the identifier of the row and the
 * two timestamps of the audit - have no field of the model to go into, so a value of a subscriber for one of them
 * is passed over rather than half-applied; on an add the deadline is not among them, because it travels in the
 * transit and an answer about it reaches the write through there. A set of bindings a subscriber emptied is not
 * taken either: an activity left without bindings is refused by the check of the fields above the step, and an
 * event standing below it may not undo the refusal.
 *
 * WHAT THE TWO OPERATIONS DISAGREE ON, and it is all this asks its caller to name:
 * - THE IDENTIFIER. An add publishes it and an update does not, because legacy drops it just above the point of
 *   the publication of an update (:616-619) and lets a subscriber read it off the first argument of the event.
 * - THE MARK. An update writes only the fields marked as changed, so a core column a subscriber changed is
 *   marked ({@see Activity::internalSetAsChanged()}) - without it a subscriber would be changing a value the
 *   write would then leave out - and a core column a subscriber took away has its mark taken off
 *   ({@see Activity::internalUnmarkChanged()}), which is legacy leaving the column out of the statement. An add
 *   writes every field that was assigned, so there taking a key away is the field going back to null - a field
 *   nobody assigned is a column the projection of an add passes over.
 *
 * @internal
 */
final class LegacyFieldsOfTheSubscribers
{
	private const COLUMN_ID = 'ID';
	private const COLUMN_BINDINGS = 'BINDINGS';
	private const COLUMN_COMMUNICATIONS = 'COMMUNICATIONS';
	private const COLUMN_STORAGE_ELEMENT_IDS = 'STORAGE_ELEMENT_IDS';
	private const COLUMN_PING_OFFSETS = 'PING_OFFSETS';
	private const COLUMN_IS_INCOMING_CHANNEL = 'IS_INCOMING_CHANNEL';

	/** The keys of the array that are collections of the model rather than columns of the table. */
	private const COLLECTION_COLUMNS = [
		self::COLUMN_BINDINGS,
		self::COLUMN_COMMUNICATIONS,
		self::COLUMN_STORAGE_ELEMENT_IDS,
		self::COLUMN_PING_OFFSETS,
		self::COLUMN_IS_INCOMING_CHANNEL,
	];

	/** @var array<string, string> the field of the payload each of the serialized columns is carried in. */
	private readonly array $payloadFieldsByColumn;

	private function __construct(
		private readonly bool $publishesTheIdentifier,
		private readonly bool $marksWhatChanged,
	)
	{
		$this->payloadFieldsByColumn = self::payloadFieldsByColumn();
	}

	public static function ofAnAdd(): self
	{
		return new self(publishesTheIdentifier: true, marksWhatChanged: false);
	}

	public static function ofAnUpdate(): self
	{
		return new self(publishesTheIdentifier: false, marksWhatChanged: true);
	}

	//region the legacy shape

	/**
	 * @return array<string, mixed> the values of the run in the shape the subscribers read.
	 */
	public function of(PipelineContext $context): array
	{
		$fields = $this->coreColumnsOf($context->getActivity());

		foreach ($context->getTransit()->getValues() as $column => $value)
		{
			$fields[$column] = self::legacyValueOf($value);
		}

		return array_merge(
			$fields,
			self::serializedColumnsOf($context->getPayload()),
			self::collectionsOf($context->getActivity()),
		);
	}

	/**
	 * @return array<string, mixed> the columns the typed model holds a value for; a field nobody assigned is a
	 *         key legacy does not have either.
	 */
	private function coreColumnsOf(Activity $activity): array
	{
		$columns = [];
		foreach (ActivityFieldMap::getCoreColumns() as $column)
		{
			if ($column === self::COLUMN_ID && !$this->publishesTheIdentifier)
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
	 * The three columns stored as one blob each, in the shape the serialization above the step left them (an add
	 * :108-126, an update :534-558): the settings and the parameters of the provider serialized, its data as it
	 * was given.
	 *
	 * @return array<string, mixed>
	 */
	private static function serializedColumnsOf(ProviderPayload $payload): array
	{
		$columns = [];

		if ($payload->has(ProviderPayload::settings))
		{
			$settings = $payload->get(ProviderPayload::settings);
			$columns['SETTINGS'] = is_array($settings) ? serialize($settings) : $settings;
		}

		if ($payload->has(ProviderPayload::providerParams))
		{
			$columns['PROVIDER_PARAMS'] = serialize($payload->get(ProviderPayload::providerParams));
		}

		if ($payload->has(ProviderPayload::providerData))
		{
			$columns['PROVIDER_DATA'] = $payload->get(ProviderPayload::providerData);
		}

		return $columns;
	}

	/**
	 * The collections legacy keeps in the same array as the columns, each in its legacy shape: the bindings and
	 * the communications as arrays of rows, the files as the serialized list of their identifiers (an add
	 * :109-116, an update :515-522), the offsets of the reminders as a list, and the incoming channel as the flag
	 * it arrived as.
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

	/**
	 * The value in the shape legacy carries rather than the shape the ORM writes: a flag is a letter and a
	 * moment is the text of the site. Everything else is already the same in both.
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

	//region what came back changed

	/**
	 * The three answers a subscriber can give, read off one comparison of the two arrays: a value that differs
	 * from the published one, a key that was not published at all, and a key that was published and is gone.
	 * A loop over the answered array alone sees the first two and never the third.
	 *
	 * @param array<string, mixed> $asPublished
	 * @param array<string, mixed> $asAnswered
	 */
	public function applyWhatSubscribersChanged(
		PipelineContext $context,
		array $asPublished,
		array $asAnswered,
	): void
	{
		foreach ($asAnswered as $column => $value)
		{
			$column = (string)$column;
			if (array_key_exists($column, $asPublished) && $asPublished[$column] === $value)
			{
				continue;
			}

			$this->applyChangedColumn($context, $column, $value);
		}

		foreach (array_keys($asPublished) as $column)
		{
			$column = (string)$column;
			if (!array_key_exists($column, $asAnswered))
			{
				$this->applyRemovedColumn($context, $column);
			}
		}
	}

	private function applyChangedColumn(PipelineContext $context, string $column, mixed $value): void
	{
		if (in_array($column, StorageFieldTransit::getSupportedFields(), true))
		{
			$context->getTransit()->set($column, $value);

			return;
		}

		$payloadField = $this->payloadFieldOf($column);
		if ($payloadField !== null)
		{
			$context->getPayload()->set($payloadField, self::payloadValueOf($payloadField, $value));

			return;
		}

		if (in_array($column, self::COLLECTION_COLUMNS, true))
		{
			self::applyChangedCollection($context->getActivity(), $column, $value);

			return;
		}

		if (self::isRestorableCoreColumn($column))
		{
			$fieldName = (string)ActivityFieldMap::getFieldName($column);
			$restored = self::restoredValueOf($context, $column, $value);

			if ($this->marksWhatChanged)
			{
				$context->getActivity()->internalSetAsChanged($fieldName, $restored);
			}
			else
			{
				$context->getActivity()->internalSet($fieldName, $restored);
			}
		}
	}

	/**
	 * A key a subscriber took out of the array is a column legacy leaves out of the write, and every carrier
	 * says that in a way of its own: the transit and the payload forget the column, a collection goes back to
	 * "the operation named none", and a core column either goes back to null or loses the mark that would have
	 * carried it into the write - see the note of the class on what the two operations disagree on.
	 */
	private function applyRemovedColumn(PipelineContext $context, string $column): void
	{
		if (in_array($column, StorageFieldTransit::getSupportedFields(), true))
		{
			$context->getTransit()->remove($column);

			return;
		}

		$payloadField = $this->payloadFieldOf($column);
		if ($payloadField !== null)
		{
			$context->getPayload()->remove($payloadField);

			return;
		}

		if (in_array($column, self::COLLECTION_COLUMNS, true))
		{
			$this->applyRemovedCollection($context->getActivity(), $column);

			return;
		}

		if (self::isRestorableCoreColumn($column))
		{
			$fieldName = (string)ActivityFieldMap::getFieldName($column);

			if ($this->marksWhatChanged)
			{
				$context->getActivity()->internalUnmarkChanged($fieldName);
			}
			else
			{
				$context->getActivity()->internalSet($fieldName, null);
			}
		}
	}

	private static function applyChangedCollection(Activity $activity, string $column, mixed $value): void
	{
		switch ($column)
		{
			case self::COLUMN_BINDINGS:
				$bindings = self::bindingsOf($value);
				// An activity cannot be left without bindings: the invariant of a non-empty set is checked
				// above the step and an event standing below it may not undo the refusal.
				if ($bindings !== [])
				{
					$activity->setBindings(...$bindings);
				}
				break;

			case self::COLUMN_COMMUNICATIONS:
				$activity->setCommunications(...self::communicationsOf($value));
				break;

			case self::COLUMN_STORAGE_ELEMENT_IDS:
				$activity->setFiles(...self::filesOf($value));
				break;

			case self::COLUMN_PING_OFFSETS:
				$activity->setPingOffsets(...self::pingOffsetsOf($value));
				break;

			case self::COLUMN_IS_INCOMING_CHANNEL:
				$activity->internalSet(Activity::isIncomingChannel, self::flagOf($value));
				break;
		}
	}

	/**
	 * The bindings are not here, and for the reason they are guarded in {@see self::applyChangedCollection()}:
	 * taking the key away would leave the activity without bindings just as emptying it would.
	 */
	private function applyRemovedCollection(Activity $activity, string $column): void
	{
		$fieldName = match ($column)
		{
			self::COLUMN_COMMUNICATIONS => Activity::communications,
			self::COLUMN_STORAGE_ELEMENT_IDS => Activity::files,
			self::COLUMN_PING_OFFSETS => Activity::pingOffsets,
			self::COLUMN_IS_INCOMING_CHANNEL => Activity::isIncomingChannel,
			default => null,
		};

		if ($fieldName === null)
		{
			return;
		}

		$activity->internalSet($fieldName, null);

		if ($this->marksWhatChanged)
		{
			$activity->internalUnmarkChanged($fieldName);
		}
	}

	/**
	 * Whether the core column has a field of the model to go into: the identifier of the row and the timestamps
	 * of the audit are assigned by the storage layer and have none.
	 */
	private static function isRestorableCoreColumn(string $column): bool
	{
		return ActivityFieldMap::hasColumn($column)
			&& ActivityFieldMap::getCategory($column) === ActivityFieldMap::CATEGORY_CORE
			&& !ActivityFieldMap::isReadOnly($column)
		;
	}

	/**
	 * The legacy value of a core column as the field of the model holds it. Only the two shapes the publication
	 * created are inverted here; for everything else the field map answers the same for both shapes.
	 */
	private static function restoredValueOf(PipelineContext $context, string $column, mixed $value): mixed
	{
		return match (ActivityFieldMap::getType($column))
		{
			ActivityFieldMap::TYPE_BOOL => self::flagOf($value),
			ActivityFieldMap::TYPE_DATETIME => self::momentOf(
				$value,
				$context->getOperationContext()->getCurrentUserId(),
			),
			default => ActivityFieldMap::toFieldValue($column, $value),
		};
	}

	private static function flagOf(mixed $value): bool
	{
		return is_string($value) ? mb_strtoupper($value) === 'Y' : (bool)$value;
	}

	/**
	 * The moment the text names, read as the local time of the acting user - the conversion legacy performs on
	 * the answered array right before its own write (:1888-1905). A value that is no moment at all reads as
	 * none, which is how the mapper of a legacy input reads the same text.
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
			return CCrmDateTimeHelper::createFromUserTime($value, $actingUserId);
		}
		catch (ObjectException)
		{
			return null;
		}
	}

	/**
	 * The field of the payload the column is carried in, or null when the column is no blob of the provider.
	 * The correspondence belongs to the field map, so there is no second copy of it here.
	 */
	private function payloadFieldOf(string $column): ?string
	{
		return $this->payloadFieldsByColumn[$column] ?? null;
	}

	/**
	 * The correspondence of the field map read backwards, once for the run rather than once per key of an
	 * answer.
	 *
	 * @return array<string, string>
	 */
	private static function payloadFieldsByColumn(): array
	{
		$fields = [];
		foreach (ProviderPayload::getSupportedFields() as $field)
		{
			$fields[ActivityFieldMap::getPayloadColumn($field)] = $field;
		}

		return $fields;
	}

	/**
	 * The inverse of the publication of the blobs: the two that went out serialized come back unserialized,
	 * because the payload carries the value itself and the write serializes it once. The data of the provider
	 * travels verbatim in both directions.
	 */
	private static function payloadValueOf(string $field, mixed $value): mixed
	{
		if ($field === ProviderPayload::providerData || !is_string($value))
		{
			return $value;
		}

		return self::unserializedOrRaw($value);
	}

	/**
	 * A string that is no serialization of anything is kept as it is: no value of the payload would reproduce
	 * it byte for byte, and dropping it would lose the answer of the subscriber altogether.
	 *
	 * A SERIALIZATION OF AN OBJECT IS KEPT AS IT IS TOO, and for a stronger reason. Unpacking one with
	 * instantiation forbidden yields the inert placeholder of a refused class rather than a value - neither an
	 * array nor false, so the test below would let it through - and the payload would carry that placeholder
	 * into the serialized column. The string itself is what legacy stores for such an answer anyway, because
	 * legacy writes the string it got back without ever unpacking it.
	 */
	private static function unserializedOrRaw(string $value): mixed
	{
		// The shape test keeps a value that is no serialization at all out of unserialize(), which warns on one,
		// and a serialized object out of it as well: 'O:' is not named here, and neither are 'C:' and 'E:'.
		if (preg_match('/^(?:N;|[abdis]:)/', $value) !== 1)
		{
			return $value;
		}

		$unserialized = unserialize($value, ['allowed_classes' => false]);
		if (self::carriesARefusedClass($unserialized))
		{
			return $value;
		}

		return $unserialized === false && $value !== serialize(false) ? $value : $unserialized;
	}

	/**
	 * Whether an object refused by the unpacking sits anywhere inside the value: nested in an array is the shape
	 * the prefix of the string does not tell, and the placeholder left in its place may reach neither the payload
	 * nor the column.
	 */
	private static function carriesARefusedClass(mixed $value): bool
	{
		if (is_object($value))
		{
			return true;
		}

		if (!is_array($value))
		{
			return false;
		}

		foreach ($value as $item)
		{
			if (self::carriesARefusedClass($item))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @return ActivityBinding[] a row that names no owner is passed over, the way the legacy write of the set
	 *         passes over a row it could not read.
	 */
	private static function bindingsOf(mixed $value): array
	{
		$bindings = [];
		foreach (is_array($value) ? $value : [] as $row)
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
	 * A row is dropped for its channel and never for its entity, the same rule the projection of the input of
	 * the caller states
	 * ({@see \Bitrix\Crm\V2\Internal\Service\Activity\Mapper\LegacyInputMapper::communicationsOf()}): what a
	 * subscriber may leave behind is what a caller may hand in, and legacy stores both alike.
	 *
	 * @return ActivityCommunication[]
	 */
	private static function communicationsOf(mixed $value): array
	{
		$communications = [];
		foreach (is_array($value) ? $value : [] as $row)
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
	 * @return ActivityStorageElement[] the files of the serialized list the column carries.
	 */
	private static function filesOf(mixed $value): array
	{
		$identifiers = is_string($value) ? self::unserializedOrRaw($value) : $value;

		$files = [];
		foreach (is_array($identifiers) ? $identifiers : [] as $identifier)
		{
			if ((int)$identifier > 0)
			{
				$files[] = new ActivityStorageElement((int)$identifier);
			}
		}

		return $files;
	}

	/**
	 * @return int[]
	 */
	private static function pingOffsetsOf(mixed $value): array
	{
		$offsets = [];
		foreach (is_array($value) ? $value : [] as $offset)
		{
			$offsets[] = (int)$offset;
		}

		return $offsets;
	}

	//endregion
}
