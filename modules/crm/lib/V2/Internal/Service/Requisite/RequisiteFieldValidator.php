<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Requisite;

use Bitrix\Crm\Attribute\FieldAttributeManager;
use Bitrix\Crm\Attribute\FieldOrigin;
use Bitrix\Crm\EntityRequisite;
use Bitrix\Crm\V2\Internal\Entity\Requisite\Preset;
use Bitrix\Crm\V2\Internal\Entity\Requisite\Requisite;
use Bitrix\Crm\V2\Internal\Entity\Requisite\RequisiteWriteData;
use Bitrix\Crm\V2\Internal\Exception\Requisite\FieldNotInPresetException;
use Bitrix\Crm\V2\Internal\Exception\Requisite\FieldNotWritableException;
use Bitrix\Crm\V2\Internal\Repository\Requisite\RequisiteColumnMap;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\Error;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\Date;

/**
 * Whether a set of fields is one a requisite of a given preset may be written with, and which of its
 * fields is the reason when it is not.
 *
 * Four ways a field is refused, and they come from three different places:
 *
 * - **not declared by the preset** - a legal detail of a jurisdiction the preset does not stand for. Ours
 *   alone: legacy has no such check and writes the value into the column.
 * - **not writable** - a field the portal fills in itself, an invariant of the owner, or one only the
 *   creation takes. The composition is the write contract of the group, settled field by field when the
 *   group was published and written down as constants in
 *   {@see \Bitrix\Crm\Tests\Unit\Lib\V2\Infrastructure\Rest\Dto\Requisite\OwnerRequisiteDtoGeneratorTest}.
 * - **too long** - the length map of the requisite is read as it is
 *   ({@see EntityRequisite::getRqFieldValidationMap()}) rather than restated here, and only the four
 *   fields the map has no rule for are named below.
 * - **required and empty** - the composition comes from the field attributes of the portal, for the scope
 *   `preset_{ID}` of the preset. An administrator configures it and nothing here may narrow or widen it.
 *
 * Every refusal names the field of the contract it is about, and that is the whole reason the first three
 * are checked here instead of being translated from the refusals of legacy: those carry the field either in
 * a separate part of the result or, on a length, nowhere but in a localized text. The name in a refusal is
 * the external name of the contract rather than a column - one vocabulary for every layer - and the text of
 * it belongs to the published errors of the domain, so a refusal carries a code and a field and no message.
 *
 * What it deliberately does not check, each for a stated reason:
 *
 * - a minimum length - every rule of the map has none, the branch is unreachable and the error code of it
 *   is dead;
 * - the type of the file of a signature or a stamp - the check never fires in legacy and the fields are
 *   not part of the contract of the group;
 * - whether the value of a field that refers to a dictionary is one of it - legacy checks it nowhere, and
 *   introducing the check would refuse values the old REST accepts;
 * - user fields of the portal - the group does not publish them at all, so the required ones among them
 *   are not asked for either;
 * - the country of the portal - it decides the title a field is refused under and nothing about which
 *   fields are required, and titles are not built here.
 *
 * Two rules of the four are stated once and read in two forms. {@see self::validate()} collects the
 * refusals of a whole set, which is the form the callers use: a client named the fields, and it has to be
 * told about all of them at once rather than about the first. {@see self::requireInPreset()} and
 * {@see self::requireWritable()} are the same two rules refusing a single field by throwing, and
 * `validate()` is written in terms of them so that a set and a single field can never be judged
 * differently.
 *
 * They are visible rather than private because that strict form is asserted on its own - the refusal it
 * throws carries the field in a typed exception, and a case reading it out of a collected list would not
 * be checking the same thing. There is no caller of them outside this class today, and none is expected:
 * the transport of the published group states writability on the fields of its request, so a field that
 * is not writable does not reach the domain from CRM code at all.
 *
 * @internal
 */
final class RequisiteFieldValidator
{
	/** The preset the write names is not one the portal offers; addressed to the field that names it. */
	public const ERROR_PRESET_NOT_FOUND = 'PRESET_NOT_FOUND';

	public const ERROR_FIELD_NOT_IN_PRESET = 'FIELD_NOT_IN_PRESET';

	public const ERROR_FIELD_NOT_WRITABLE = 'FIELD_NOT_WRITABLE';

	public const ERROR_FIELD_TOO_LONG = 'FIELD_TOO_LONG';

	public const ERROR_FIELD_REQUIRED = 'FIELD_REQUIRED';

	/** The key the refused field travels under in {@see Error::getCustomData()}. */
	public const ERROR_FIELD = 'field';

	/** The key the limit of a length travels under: the phrase of the refusal states it. */
	public const ERROR_MAX_LENGTH = 'maxLength';

	/**
	 * Fields no write takes from a caller: the moments and the authors of the record are the portal's to
	 * fill in, and the type of the owner is an invariant of the route rather than a value of the record.
	 *
	 * The identifier of the record is not listed because it is refused earlier and harder - a set cannot
	 * carry it at all ({@see RequisiteColumnMap::requireWriteColumn()}).
	 */
	private const FIELDS_NEVER_WRITABLE = [
		Requisite::FIELD_OWNER_TYPE_ID => true,
		Requisite::FIELD_CREATED_TIME => true,
		Requisite::FIELD_UPDATED_TIME => true,
		Requisite::FIELD_CREATED_BY_ID => true,
		Requisite::FIELD_UPDATED_BY_ID => true,
	];

	/**
	 * The owner of a requisite is named once, when it is created: moving a requisite between owners is not
	 * published by the group. Legacy can do it and its facade keeps doing it.
	 */
	private const FIELDS_WRITABLE_ON_ADD_ONLY = [
		Requisite::FIELD_OWNER_ID => true,
	];

	/**
	 * The four limits the length map of the requisite does not carry. Named here rather than left
	 * unchecked, because the map being silent about a field is a gap of the map and not the absence of a
	 * limit: `name` and `xmlId` are limited by the validators of the ORM alone
	 * ({@see \Bitrix\Crm\RequisiteTable::validateName()}, {@see \Bitrix\Crm\RequisiteTable::validateXmlId()}),
	 * `taxRegimeId` is limited by one as well and is the single field the map and the ORM disagree about,
	 * and `originatorId` is limited by nothing but the width of its column - on the legacy path a longer
	 * value is silently cut down by a database that is not in strict mode.
	 */
	private const MAX_LENGTH_OUTSIDE_THE_LEGACY_MAP = [
		Requisite::FIELD_NAME => 255,
		Requisite::FIELD_XML_ID => 45,
		Requisite::FIELD_TAX_REGIME_ID => 50,
		Requisite::FIELD_ORIGINATOR_ID => 255,
	];

	/** @var array<string, int>|null */
	private static ?array $maxLengthByField = null;

	public function __construct(private readonly PresetResolver $presets = new PresetResolver())
	{
	}

	/**
	 * Every refusal the set carries, and an empty list when the write may proceed.
	 *
	 * The order is the contract of the method: the preset first - without one there is nothing to check a
	 * field against - then the fields the caller named, in the order it named them and at most one
	 * refusal each, and the required fields last. A caller that reports the first refusal alone reports
	 * the same one this list starts with.
	 *
	 * The required fields of a creation are all of them, and of a change only the ones the set carries:
	 * that is how a requisite is written today ({@see EntityRequisite::checkRequiredFieldsBeforeSave()})
	 * and changing it would refuse changes that go through now.
	 *
	 * @param int $presetId the preset the requisite is written against - the one it names now on a
	 *        change, or the new one when the change names another.
	 * @param bool $isAdd whether the write creates the requisite rather than changing one.
	 *
	 * @return Error[] each one carrying the field of the contract it is about in `['field' => ...]` of
	 *         its custom data, and no message: the phrases of the domain belong to its published errors.
	 *
	 * @throws SystemException a failure of the storage while reading the preset or the field attributes.
	 */
	public function validate(int $presetId, RequisiteWriteData $fields, bool $isAdd): array
	{
		$preset = $this->presets->findAvailable($presetId);
		if ($preset === null)
		{
			return [self::error(self::ERROR_PRESET_NOT_FOUND, Requisite::FIELD_PRESET_ID)];
		}

		$errors = [];
		foreach ($fields->fields() as $field)
		{
			try
			{
				$this->requireInPreset($preset, $field);
			}
			catch (FieldNotInPresetException $refusal)
			{
				$errors[] = self::error(self::ERROR_FIELD_NOT_IN_PRESET, $refusal->getFieldName());

				continue;
			}

			try
			{
				$this->requireWritable($field, $isAdd);
			}
			catch (FieldNotWritableException $refusal)
			{
				$errors[] = self::error(self::ERROR_FIELD_NOT_WRITABLE, $refusal->getFieldName());

				continue;
			}

			$tooLong = self::tooLongError($field, $fields->get($field));
			if ($tooLong !== null)
			{
				$errors[] = $tooLong;
			}
		}

		return array_merge($errors, self::missingErrors($this->requiredFields($presetId), $fields, $isAdd));
	}

	/**
	 * @throws FieldNotInPresetException the preset does not declare $field, and it is a field a preset
	 *         decides about: the properties a requisite has as a record do not go through this check,
	 *         because no composition of any preset mentions them
	 *         ({@see RequisiteColumnMap::isFieldOfPreset()}).
	 */
	public function requireInPreset(Preset $preset, string $field): void
	{
		if (RequisiteColumnMap::isFieldOfPreset($field) && !$preset->hasField($field))
		{
			throw new FieldNotInPresetException($field, $preset->id);
		}
	}

	/**
	 * @param bool $isAdd whether the write creates the requisite: the owner of one is named at creation
	 *        and not changed afterwards.
	 *
	 * @throws FieldNotWritableException $field is not one this write takes from a caller, or is no field
	 *         of a requisite at all.
	 */
	public function requireWritable(string $field, bool $isAdd): void
	{
		$refused =
			isset(self::FIELDS_NEVER_WRITABLE[$field])
			|| (!$isAdd && isset(self::FIELDS_WRITABLE_ON_ADD_ONLY[$field]))
			|| !isset(RequisiteColumnMap::columnByWriteField()[$field])
		;

		if ($refused)
		{
			throw new FieldNotWritableException($field);
		}
	}

	/**
	 * The fields the portal requires of a requisite of $presetId, by their names in the contract.
	 *
	 * The identifier of the preset is passed to the mechanism of the field attributes rather than left to it:
	 * without one it goes looking for the preset of the record and, finding none, fails with a system
	 * exception from its own depth (`fieldattributemanager.php`). Only the fields of the record are asked
	 * for - the required user fields of the portal belong to the write of legacy, which the group does not
	 * publish.
	 *
	 * A required column with no field of the contract behind it - the marker of the address of a requisite
	 * is the one that exists - is left out: this write neither carries it nor has a name to refuse it
	 * under.
	 *
	 * @return string[]
	 *
	 * @throws SystemException
	 */
	private function requiredFields(int $presetId): array
	{
		$columns = FieldAttributeManager::getRequiredFields(
			OwnerType::REQUISITE,
			0,
			[],
			FieldOrigin::SYSTEM,
			['PRESET_ID' => $presetId],
		);

		$fields = [];
		foreach ($columns as $column)
		{
			$field = RequisiteColumnMap::findFieldByColumn((string)$column);
			if ($field !== null)
			{
				$fields[] = $field;
			}
		}

		return $fields;
	}

	/**
	 * @param string[] $requiredFields
	 *
	 * @return Error[]
	 */
	private static function missingErrors(array $requiredFields, RequisiteWriteData $fields, bool $isAdd): array
	{
		$errors = [];
		foreach ($requiredFields as $field)
		{
			if (!$isAdd && !$fields->has($field))
			{
				continue;
			}

			if (self::isEmpty($fields->get($field)))
			{
				$errors[] = self::error(self::ERROR_FIELD_REQUIRED, $field);
			}
		}

		return $errors;
	}

	/**
	 * Empty is what legacy treats as absent ({@see \Bitrix\Crm\Entity\EntityValidator}): no value at all
	 * or the empty string. A boolean field is never empty - false is an answer - and neither is a zero.
	 */
	private static function isEmpty(int|bool|string|Date|null $value): bool
	{
		return $value === null || $value === '';
	}

	private static function tooLongError(string $field, int|bool|string|Date|null $value): ?Error
	{
		$max = self::maxLengthByField()[$field] ?? 0;
		if ($max <= 0 || is_bool($value) || !is_scalar($value))
		{
			return null;
		}

		// A value that is the empty string is not measured, as legacy does not measure it either, and the
		// length is counted in characters rather than bytes.
		$text = (string)$value;
		if ($text === '' || mb_strlen($text) <= $max)
		{
			return null;
		}

		return new Error('', self::ERROR_FIELD_TOO_LONG, [
			self::ERROR_FIELD => $field,
			self::ERROR_MAX_LENGTH => $max,
		]);
	}

	/**
	 * The longest value each field takes, by the name of the field in the contract.
	 *
	 * The map of the requisite is read as it is: it is the one the write of legacy checks against, and a copy
	 * of it here would drift from it field by field. Only the rules of a length are taken from it - the two
	 * entries that describe the type of a file are the check that never fires - and the columns it is written
	 * in are translated into the names of the contract.
	 *
	 * Read once per process: the map is a constant of the module built as an array of arrays.
	 *
	 * @return array<string, int>
	 */
	private static function maxLengthByField(): array
	{
		if (self::$maxLengthByField !== null)
		{
			return self::$maxLengthByField;
		}

		$fromTheMap = [];
		foreach (EntityRequisite::getSingleInstance()->getRqFieldValidationMap() as $column => $rules)
		{
			foreach ((is_array($rules) ? $rules : []) as $rule)
			{
				$max = (int)($rule['params']['max'] ?? 0);
				$field = RequisiteColumnMap::findFieldByColumn((string)$column);
				if (($rule['type'] ?? '') === 'length' && $max > 0 && $field !== null)
				{
					$fromTheMap[$field] = $max;
				}
			}
		}

		// The map wins where it speaks: the four limits below are the fields it says nothing about, and a
		// rule appearing there later is the one to check against rather than ours.
		self::$maxLengthByField = $fromTheMap + self::MAX_LENGTH_OUTSIDE_THE_LEGACY_MAP;

		return self::$maxLengthByField;
	}

	private static function error(string $code, string $field): Error
	{
		return new Error('', $code, [self::ERROR_FIELD => $field]);
	}
}
