<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Filter\Field;

/**
 * Which modes a filter field offers, whether the tag union is available and whether the field takes part
 * in the operator between member fields.
 *
 * Typed contract behind the `filterMode` key of a filter field definition: Helper\Filter builds
 * the declaration, the task list template hands it to the tasks.filter-field-mode extension, and
 * Helper\Filter reads it back when translating the mode picked by the user. The array form exists
 * only because main.ui.filter field definitions are plain arrays.
 */
final class ModeDeclaration
{
	public const FIELD_KEY = 'filterMode';

	private const MODES_KEY = 'modes';
	private const UNION_KEY = 'union';
	private const MEMBER_UNION_KEY = 'memberUnion';

	/** @var list<Mode> */
	private readonly array $modes;

	/**
	 * @param list<Mode> $modes Offered modes, in display order.
	 */
	public function __construct(
		array $modes,
		private readonly bool $unionAvailable = false,
		private readonly bool $memberUnionAvailable = false,
	)
	{
		$this->modes = array_values($modes);
	}

	/**
	 * Declaration carried by a filter field definition, or null when the field offers no modes.
	 */
	public static function fromFieldDefinition(array $field): ?self
	{
		$raw = $field[self::FIELD_KEY] ?? null;
		if (!is_array($raw) || !is_array($raw[self::MODES_KEY] ?? null))
		{
			return null;
		}

		$modes = [];
		foreach ($raw[self::MODES_KEY] as $value)
		{
			$mode = is_string($value) ? Mode::tryFrom($value) : null;
			if ($mode !== null)
			{
				$modes[] = $mode;
			}
		}

		if ($modes === [])
		{
			return null;
		}

		return new self(
			$modes,
			($raw[self::UNION_KEY] ?? null) === true,
			($raw[self::MEMBER_UNION_KEY] ?? null) === true,
		);
	}

	public function toArray(): array
	{
		$result = [
			self::MODES_KEY => array_map(static fn(Mode $mode): string => $mode->value, $this->modes),
		];

		if ($this->unionAvailable)
		{
			$result[self::UNION_KEY] = true;
		}

		if ($this->memberUnionAvailable)
		{
			$result[self::MEMBER_UNION_KEY] = true;
		}

		return $result;
	}

	/**
	 * Mode picked by the user, falling back to Mode::EQUAL for anything this field does not offer.
	 */
	public function resolveMode(mixed $rawMode): Mode
	{
		$mode = is_string($rawMode) ? Mode::tryFrom($rawMode) : null;

		return ($mode !== null && in_array($mode, $this->modes, true)) ? $mode : Mode::EQUAL;
	}

	public function isUnionAvailable(): bool
	{
		return $this->unionAvailable;
	}

	public function isMemberUnionAvailable(): bool
	{
		return $this->memberUnionAvailable;
	}
}
