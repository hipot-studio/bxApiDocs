<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Requisite;

use Bitrix\Crm\EntityRequisite;
use Bitrix\Crm\V2\Internal\Entity\Requisite\Preset;
use Bitrix\Crm\V2\Internal\Repository\Requisite\RequisiteColumnMap;
use Bitrix\Main\SystemException;

/**
 * Narrows a set of requisite fields that came from outside the portal down to the composition of the
 * preset it is meant for.
 *
 * Such a set is assembled by something that knows nothing of the preset the portal configured: an
 * application that searches an organization by its registration number, or the resolver of the product
 * for Russia and Ukraine. It carries every legal detail its own source knows, and the requisite it is
 * poured into shows only the details its preset declares. Without this rule a value of a field the user
 * never saw reaches the write, where it is refused for a length nobody could have corrected in the form.
 *
 * A pure transformation: it reads no requisite, writes nothing and checks no rights. The values of the
 * fields it keeps are handed back exactly as they arrived - no trimming and no normalization - because a
 * set that passes through here has to remain the set the source produced.
 *
 * Two things the rule deliberately leaves alone:
 *
 * - **keys that are not fields of a requisite** - `PRESET_ID`, `PRESET_COUNTRY_ID`, the user fields of
 *   the portal and whatever else a caller carries alongside the fields. A preset decides nothing about
 *   them, so they pass as they are;
 * - **the address** - {@see EntityRequisite::ADDRESS} is a record of its own rather than a column of a
 *   requisite, and it is listed among the fields of a requisite all the same. It is answered for before
 *   anything else, because the rule below would otherwise drop it as a field no preset declares.
 *
 * The user fields of a preset are outside the rule as well: the composition does carry them
 * ({@see \Bitrix\Crm\V2\Internal\Entity\Requisite\PresetField::$userField}), but no source of an external
 * set fills one in, so narrowing them would be a rule without a case.
 *
 * @internal
 */
final class ExternalFieldSetFilter
{
	/** The key a set names the preset it is meant for under - the column of a requisite, not a field. */
	private const PRESET_ID_KEY = 'PRESET_ID';

	/** @var array<string, true>|null */
	private static ?array $requisiteFieldNames = null;

	public function __construct(private readonly PresetResolver $presets = new PresetResolver())
	{
	}

	/**
	 * $fieldSet without the fields the preset does not declare.
	 *
	 * The preset is the one the set names, and $currentPresetId only when it names none: a set that
	 * brings a preset of its own is about to change the preset of the requisite, and checking it against
	 * the one being replaced would drop exactly the fields the new preset was chosen for.
	 *
	 * The set is returned untouched when no preset can be named, and when the preset named is not there.
	 * Both are the same decision: nothing is known about what the requisite may hold, and dropping fields
	 * on a guess would empty a form that used to fill in.
	 *
	 * @param array<string, mixed> $fieldSet requisite column names and their values, as the external
	 *        source produced them.
	 * @param int $currentPresetId the preset the requisite names now, or `0` when the caller knows none.
	 *
	 * @return array<string, mixed> the same values under the same keys, in the same order.
	 *
	 * @throws SystemException a failure of the storage while reading the preset.
	 */
	public function filter(array $fieldSet, int $currentPresetId = 0): array
	{
		$targetPresetId = (int)($fieldSet[self::PRESET_ID_KEY] ?? 0);
		if ($targetPresetId <= 0)
		{
			$targetPresetId = $currentPresetId;
		}

		if ($targetPresetId <= 0)
		{
			return $fieldSet;
		}

		// A preset the portal switched off still decides what the requisites created on it hold, so the
		// question asked is whether the preset is there rather than whether it is offered.
		$preset = $this->presets->find($targetPresetId);
		if ($preset === null)
		{
			return $fieldSet;
		}

		$filtered = [];
		foreach ($fieldSet as $key => $value)
		{
			if (self::isDeclaredBy($preset, (string)$key))
			{
				$filtered[$key] = $value;
			}
		}

		return $filtered;
	}

	private static function isDeclaredBy(Preset $preset, string $key): bool
	{
		if ($key === EntityRequisite::ADDRESS || !isset(self::requisiteFieldNames()[$key]))
		{
			return true;
		}

		// The composition of a preset is answered for by the field names of the contract, and an external
		// set speaks columns; the map that translates one into the other is the one the composition itself
		// was read through. A column with no field behind it cannot be asked about, so it passes.
		$field = RequisiteColumnMap::findFieldByColumn($key);

		return $field === null || $preset->hasField($field);
	}

	/**
	 * The fields of a requisite, as a set: every key of every external set is looked up in it, and the
	 * list is a constant of the module.
	 *
	 * @return array<string, true>
	 */
	private static function requisiteFieldNames(): array
	{
		self::$requisiteFieldNames ??= array_fill_keys(
			EntityRequisite::getSingleInstance()->getRqFields(),
			true,
		);

		return self::$requisiteFieldNames;
	}
}
