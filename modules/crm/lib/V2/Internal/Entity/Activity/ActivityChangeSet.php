<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Activity;

use Bitrix\Crm\V2\Internal\Entity\Activity\Enum\CompletionStatus;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Type\DateTime;

/**
 * The "before -> after" set of one write of an activity: what the pipeline computes the conditions
 * of its side-effect steps from.
 *
 * THE NAMES ARE A CONTRACT. The step tables of the pipeline refer to the names below, so the set
 * accepts nothing else. Sources of three of them lie outside the typed model - the notify pair and
 * the storage elements come from the repositories, the incoming-channel flag and the ping offsets
 * from the steps that resolve them.
 *
 * THE SET READS NOTHING. It receives ready pairs from whoever owns the source and only answers
 * whether a name changed and what its two values were.
 *
 * WHAT "CHANGED" MEANS. Comparing is the one piece of behaviour here, and the semantics of it live
 * in this class alone: the deadline is compared through its string form, as legacy did, collections
 * as unordered sets of their keys. Where legacy had to cast - the responsible - the accepted type is
 * already an integer, so a numeric string can never be mistaken for a change.
 *
 * @internal
 */
final class ActivityChangeSet
{
	public const isCompleted = Activity::isCompleted;
	public const completionStatus = Activity::completionStatus;
	public const responsibleId = Activity::responsibleId;
	public const deadline = Activity::deadline;
	public const bindings = Activity::bindings;
	public const communications = Activity::communications;
	public const isIncomingChannel = Activity::isIncomingChannel;
	public const pingOffsets = Activity::pingOffsets;

	/** The NOTIFY_TYPE + NOTIFY_VALUE pair; the model has no such field, the repository supplies it. */
	public const notify = 'notify';

	/** Element ids of `b_crm_act_elem`; the model holds the files themselves, the repository the ids. */
	public const storageElements = 'storageElements';

	private const NAMES = [
		self::isCompleted,
		self::completionStatus,
		self::responsibleId,
		self::deadline,
		self::bindings,
		self::communications,
		self::isIncomingChannel,
		self::notify,
		self::pingOffsets,
		self::storageElements,
	];

	private const DEADLINE_COMPARISON_FORMAT = 'Y-m-d H:i:s';

	/** @var array<string, array{previous: mixed, current: mixed}> */
	private array $pairs = [];

	/**
	 * The closed list of change names the set accepts.
	 *
	 * @return string[]
	 */
	public static function getSupportedNames(): array
	{
		return self::NAMES;
	}

	/**
	 * Supplies the pair of values of one name. The owner of the source calls it once; calling it
	 * again replaces the pair.
	 *
	 * @throws ArgumentException on an unknown name or on a value of the wrong type.
	 */
	public function set(string $name, mixed $previous, mixed $current): static
	{
		self::assertName($name);
		self::assertValue($name, $previous);
		self::assertValue($name, $current);

		$this->pairs[$name] = [
			'previous' => $previous,
			'current' => $current,
		];

		return $this;
	}

	/**
	 * Whether the pair of the name was supplied at all. A name nobody supplied is not "unchanged",
	 * it is unknown - the step that needs it has no source yet.
	 */
	public function has(string $name): bool
	{
		self::assertName($name);

		return isset($this->pairs[$name]);
	}

	/**
	 * @throws ArgumentException when the pair of the name was not supplied.
	 */
	public function getPrevious(string $name): mixed
	{
		return $this->getPair($name)['previous'];
	}

	/**
	 * @throws ArgumentException when the pair of the name was not supplied.
	 */
	public function getCurrent(string $name): mixed
	{
		return $this->getPair($name)['current'];
	}

	/**
	 * Whether the two values of the name differ. A name that was not supplied did not change.
	 */
	public function isChanged(string $name): bool
	{
		if (!$this->has($name))
		{
			return false;
		}

		$pair = $this->pairs[$name];

		return self::comparableValue($name, $pair['previous']) !== self::comparableValue($name, $pair['current']);
	}

	/**
	 * @return string[]
	 */
	public function getChangedNames(): array
	{
		return array_values(array_filter(self::NAMES, fn(string $name): bool => $this->isChanged($name)));
	}

	public function hasChanges(): bool
	{
		return $this->getChangedNames() !== [];
	}

	/**
	 * @return array{previous: mixed, current: mixed}
	 */
	private function getPair(string $name): array
	{
		if (!$this->has($name))
		{
			throw new ArgumentException("No pair of '{$name}' was supplied to the change set");
		}

		return $this->pairs[$name];
	}

	private static function assertName(string $name): void
	{
		if (!in_array($name, self::NAMES, true))
		{
			throw new ArgumentException("'{$name}' is not a change of an activity");
		}
	}

	private static function assertValue(string $name, mixed $value): void
	{
		$isValid = match ($name)
		{
			self::isCompleted, self::isIncomingChannel => is_bool($value),
			self::completionStatus => $value instanceof CompletionStatus,
			self::responsibleId => is_int($value),
			self::deadline => $value === null || $value instanceof DateTime,
			self::bindings => self::isListOf($value, static fn(mixed $item): bool => $item instanceof ActivityBinding),
			self::communications => self::isListOf(
				$value,
				static fn(mixed $item): bool => $item instanceof ActivityCommunication,
			),
			self::pingOffsets, self::storageElements => self::isListOf($value, 'is_int'),
			self::notify => self::isNotifyPair($value),
		};

		if (!$isValid)
		{
			throw new ArgumentException("The value supplied for '{$name}' is not of the type of that change");
		}
	}

	/**
	 * The comparable form of a value: two values of one name are the same change exactly when
	 * their comparable forms are identical.
	 */
	private static function comparableValue(string $name, mixed $value): mixed
	{
		return match ($name)
		{
			self::deadline => $value?->format(self::DEADLINE_COMPARISON_FORMAT),
			self::bindings, self::communications => self::sortedKeys($value),
			self::pingOffsets, self::storageElements => self::sortedValues($value),
			self::notify => [$value['type'], $value['value']],
			default => $value,
		};
	}

	private static function isListOf(mixed $value, callable $isValidItem): bool
	{
		if (!is_array($value))
		{
			return false;
		}

		foreach ($value as $item)
		{
			if (!$isValidItem($item))
			{
				return false;
			}
		}

		return true;
	}

	private static function isNotifyPair(mixed $value): bool
	{
		if (!is_array($value) || count($value) !== 2)
		{
			return false;
		}

		foreach (['type', 'value'] as $key)
		{
			if (!array_key_exists($key, $value) || ($value[$key] !== null && !is_int($value[$key])))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * @param ActivityBinding[]|ActivityCommunication[] $items
	 * @return string[]
	 */
	private static function sortedKeys(array $items): array
	{
		$keys = array_map(static fn(object $item): string => $item->getKey(), $items);
		sort($keys);

		return $keys;
	}

	/**
	 * @param int[] $values
	 * @return int[]
	 */
	private static function sortedValues(array $values): array
	{
		sort($values);

		return $values;
	}
}
