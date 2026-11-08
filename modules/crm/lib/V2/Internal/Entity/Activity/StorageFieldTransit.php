<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Activity;

use Bitrix\Main\ArgumentException;

/**
 * Carries the storage columns of a legacy input that the typed model cannot write, or writes in a
 * poorer form, from the entry point of the pipeline down to the repository and to the steps that
 * need them.
 *
 * WHY IT EXISTS. Three of the columns below are core fields of the model, and they are here for
 * two different reasons. The priority has three grades where the model has a flag and an explicitly
 * given owner beats the one derived from the bindings, so the legacy form of both is the richer one.
 * The deadline is here because the model has no way to write it at all: it is read-only and derived,
 * and its only writer in legacy is a provider. Everything else is either storage-only or belongs to
 * a provider. Losing any of them silently would change what gets written.
 *
 * A DEADLINE OF THE CALLER NEVER TRAVELS HERE. It has no effect on any legacy operation - it is
 * dropped before the provider runs, on an add (crm_activity.php:2007) and on an update (:2127) alike -
 * so the facade may not put one here either. What does travel here is the deadline a provider step
 * derived and, on an add where no provider derived any, the start time of the activity, which the check
 * of the fields puts here as legacy does (:2158-2173). A run that carries no deadline at all leaves the
 * repository to fill the column with the max-date sentinel of "no deadline".
 *
 * TWO OF THE COLUMNS NO WRITE OF V2 PRODUCES STILL TRAVEL HERE - the group of the provider and the
 * value of the result - and that is the same reason as the rest: legacy assembles its row out of the
 * field array of the caller, so a column the caller named is written and a column it did not name keeps
 * whatever the row holds. Nothing of V2 interprets either of them; without a carrier the value of the
 * caller was simply dropped on the way to the write, which is what the repository fills the row from.
 * The value of the result is no exception to ALG-05 either: it is not statistical data a provider
 * derives, so no step materializes it and no default is written for it.
 *
 * THE LIST IS CLOSED. Carrying one more column means changing this list and the field map
 * together - the acceptance test walks the list, so a column added in passing stays invisible.
 * The raw serialized fields are not here: their carrier is {@see ProviderPayload}.
 *
 * NOTHING HERE IS INTERPRETED. The core moves the values, the provider layer and the repository
 * read them.
 *
 * WRITING AND READING ARE DIFFERENT QUESTIONS WITH DIFFERENT ANSWERS. No column of the list is
 * writable through the typed model - the transit is the one way into the row, which is what keeps
 * the column from having two writers. Reading is not so: the type, the owner pair, the priority, the
 * origin and the associated entity reach a read as the read-only projection of the model. The rest of
 * the list reaches no model at all.
 *
 * @internal
 */
final class StorageFieldTransit
{
	private const FIELDS = [
		'NOTIFY_TYPE',
		'NOTIFY_VALUE',
		'OWNER_ID',
		'OWNER_TYPE_ID',
		'TYPE_ID',
		'THREAD_ID',
		'STORAGE_TYPE_ID',
		'IS_HANDLEABLE',
		'PRIORITY',
		'DEADLINE',
		'URN',
		'ASSOCIATED_ENTITY_ID',
		'CALENDAR_EVENT_ID',
		'LOCATION',
		'ORIGINATOR_ID',
		'ORIGIN_ID',
		'RESULT_STATUS',
		'RESULT_STREAM',
		'RESULT_SOURCE_ID',
		'RESULT_MARK',
		'RESULT_SUM',
		'RESULT_CURRENCY_ID',
		'RESULT_VALUE',
		'PROVIDER_GROUP_ID',
	];

	/** @var array<string, mixed> */
	private array $values = [];

	/**
	 * The closed list of columns the transit carries.
	 *
	 * @return string[]
	 */
	public static function getSupportedFields(): array
	{
		return self::FIELDS;
	}

	/**
	 * Whether the input carried the column. Tells "not given" from "given as null": several of
	 * these columns are only written when the caller asked for it.
	 */
	public function has(string $field): bool
	{
		self::assertField($field);

		return array_key_exists($field, $this->values);
	}

	/**
	 * @throws ArgumentException when the input did not carry the column.
	 */
	public function get(string $field): mixed
	{
		if (!$this->has($field))
		{
			throw new ArgumentException("The transit does not carry '{$field}'");
		}

		return $this->values[$field];
	}

	public function set(string $field, mixed $value): static
	{
		self::assertField($field);
		$this->values[$field] = $value;

		return $this;
	}

	/**
	 * Drops the column from the transit. A step that consumes a column takes it out so that it is
	 * not written twice.
	 */
	public function remove(string $field): static
	{
		self::assertField($field);
		unset($this->values[$field]);

		return $this;
	}

	/**
	 * Every column the input carried, by column name.
	 *
	 * @return array<string, mixed>
	 */
	public function getValues(): array
	{
		return $this->values;
	}

	private static function assertField(string $field): void
	{
		if (!in_array($field, self::FIELDS, true))
		{
			throw new ArgumentException("'{$field}' is not a column of the storage field transit");
		}
	}
}
