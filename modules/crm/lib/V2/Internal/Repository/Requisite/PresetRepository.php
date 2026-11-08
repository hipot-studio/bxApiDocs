<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Requisite;

use Bitrix\Crm\PresetTable;
use Bitrix\Crm\V2\Internal\Entity\Requisite\Preset;
use Bitrix\Crm\V2\Internal\Entity\Requisite\PresetField;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\SystemException;

/**
 * The single point the new layer reads the presets of requisites through.
 *
 * Read-only, and not because nothing writes presets - the settings of the portal do - but because the
 * group only reads them. That includes the write legacy performs while reading: resolving the default
 * preset caches its answer back into a portal-wide option, and this class does nothing of the kind.
 *
 * The composition of presets the portal offers is `ACTIVE` presets of the requisite entity type, ordered
 * as the caller asks and, when it asks for nothing, by sort and then by identifier - the order the card of
 * a requisite shows them in. The country of the portal does not narrow it: the branch that would has stood
 * under a condition that cannot be false since {@see \Bitrix\Crm\EntityPreset::isUTFMode()} became a
 * literal `true`, so what this class answers is what the card of a requisite shows. Narrowing it here
 * would not fix a filter - it would change the presets every portal sees.
 *
 * The composition of one preset lives in a serialized column, and this class is where it is read: the
 * setting speaks storage names, and translating them is what the boundary is for. What comes out is field
 * names of the contract, translated through {@see RequisiteColumnMap} - the map the requisites of the
 * same area are read and written by, read backwards. Two names in the setting are not columns at all and
 * are marked instead of translated: the marker of the address and a user field of the portal.
 *
 * ORM objects never leave the class.
 *
 * @internal
 */
final class PresetRepository
{
	/**
	 * The entry of a composition that says the preset shows the address of the requisite. Not a column:
	 * addresses of a requisite are records of their own, and legacy recognizes the marker by comparing the
	 * name literally ({@see \Bitrix\Crm\EntityRequisite::ADDRESS}).
	 */
	private const ADDRESS_MARKER = 'RQ_ADDR';

	/**
	 * A user field of the portal carries this prefix and a column of a requisite never does, so the prefix
	 * settles which of the two an unknown name is without asking the portal for its list of user fields.
	 */
	private const USER_FIELD_PREFIX = 'UF_';

	private const COLUMNS = ['ID', 'NAME', 'ACTIVE', 'COUNTRY_ID', 'SORT', 'SETTINGS'];

	private const ORDER_FIELD_ID = 'id';

	private const ORDER_FIELD_SORT = 'sort';

	/**
	 * The fields the composition may be ordered by, and the columns behind them. A caller of this class
	 * speaks the field names of the contract, exactly as it does everywhere else here, and these two are
	 * the ones the contract of a preset declares sortable; asking for any other is refused rather than
	 * dropped, because an answer ordered by something else than was asked for is the mistake hardest to
	 * notice.
	 */
	private const COLUMN_BY_ORDER_FIELD = [
		self::ORDER_FIELD_ID => 'ID',
		self::ORDER_FIELD_SORT => 'SORT',
	];

	/**
	 * The preset $id names, whether the portal offers it or not - a preset switched off is still the
	 * preset the requisites created on it name, and telling that from a preset that was never there is the
	 * caller's to do through {@see Preset::$active}.
	 *
	 * @throws SystemException a failure of the query itself.
	 */
	public function findById(int $id): ?Preset
	{
		if ($id <= 0)
		{
			return null;
		}

		$row = PresetTable::query()
			->setSelect(self::COLUMNS)
			->where('ID', $id)
			->where('ENTITY_TYPE_ID', OwnerType::REQUISITE)
			->fetch()
		;

		return is_array($row) ? self::createPreset($row) : null;
	}

	/**
	 * The presets the portal offers, ordered as asked and, when nothing is asked, in the order the
	 * interface shows them.
	 *
	 * The order reaches the storage: it is part of the query rather than something done to the rows
	 * afterwards, which is what keeps it true of a page of them.
	 *
	 * @param array<string, string> $order {@see self::COLUMN_BY_ORDER_FIELD} field name => `ASC`/`DESC`;
	 *        the identifier settles whatever the ordering leaves equal, so two presets of one sort do not
	 *        change places between two identical reads. An empty ordering is the order the interface shows
	 *        the presets in - by sort, then by identifier.
	 * @return Preset[]
	 * @throws ArgumentException an ordering over a field the contract of a preset does not declare
	 *         sortable.
	 * @throws SystemException a failure of the query itself.
	 */
	public function findActiveOfPortal(array $order = []): array
	{
		$rows = PresetTable::query()
			->setSelect(self::COLUMNS)
			->where('ENTITY_TYPE_ID', OwnerType::REQUISITE)
			->where('ACTIVE', 'Y')
			->setOrder(self::translateOrder($order))
			->fetchAll()
		;

		$presets = [];
		foreach ($rows as $row)
		{
			$presets[] = self::createPreset($row);
		}

		return $presets;
	}

	/**
	 * The preset the external code $xmlId names, by identifier, and `0` when the portal has none.
	 *
	 * The shipped presets of the product carry such a code, and it is how the default preset of an owner
	 * type is resolved when the portal has not chosen one of its own. Nothing narrows the answer -
	 * neither activity nor the country of the preset - because legacy resolves it by the same unfiltered
	 * read ({@see \Bitrix\Crm\EntityRequisite::getDefaultPresetId()}), and a preset the portal switched
	 * off is still the one that rule names. What follows from that is the caller's to decide
	 * ({@see \Bitrix\Crm\V2\Internal\Service\Requisite\PresetResolver::listPresets()}).
	 *
	 * An external code is not unique in the storage, so the lowest identifier settles which of two rows
	 * is answered; legacy takes whatever the storage hands back first, which is the same row until the
	 * day it is not.
	 *
	 * @throws SystemException a failure of the query itself.
	 */
	public function findIdByXmlId(string $xmlId): int
	{
		if ($xmlId === '')
		{
			return 0;
		}

		$row = PresetTable::query()
			->setSelect(['ID'])
			->where('XML_ID', $xmlId)
			->where('ENTITY_TYPE_ID', OwnerType::REQUISITE)
			->setOrder(['ID' => 'ASC'])
			->fetch()
		;

		return is_array($row) ? (int)$row['ID'] : 0;
	}

	/**
	 * @param array<string, string> $order
	 * @return array<string, string> never empty: an ordering that names nothing is the one the interface
	 *         shows the presets in, and the identifier closes whatever any ordering leaves equal.
	 * @throws ArgumentException
	 */
	private static function translateOrder(array $order): array
	{
		$translated = [];
		foreach ($order as $field => $direction)
		{
			$translated[self::requireOrderColumn((string)$field)] = $direction;
		}

		if ($translated === [])
		{
			$translated[self::COLUMN_BY_ORDER_FIELD[self::ORDER_FIELD_SORT]] = 'ASC';
		}

		$translated[self::COLUMN_BY_ORDER_FIELD[self::ORDER_FIELD_ID]] ??= 'ASC';

		return $translated;
	}

	/**
	 * @throws ArgumentException
	 */
	private static function requireOrderColumn(string $field): string
	{
		return self::COLUMN_BY_ORDER_FIELD[$field]
			?? throw new ArgumentException('The presets are not ordered by such a field: ' . $field, 'order');
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function createPreset(array $row): Preset
	{
		return new Preset(
			id: (int)$row['ID'],
			name: (string)($row['NAME'] ?? ''),
			// The column keeps a letter and the ORM declares the field boolean, so what comes back depends
			// on the converter having been applied. Both answers say the same thing.
			active: ($row['ACTIVE'] ?? null) === true || ($row['ACTIVE'] ?? null) === 'Y',
			countryId: (int)($row['COUNTRY_ID'] ?? 0),
			sort: (int)($row['SORT'] ?? 0),
			fields: self::createFields($row['SETTINGS'] ?? null),
		);
	}

	/**
	 * The composition of a preset, ordered as the card of a requisite orders it - by the sort of the
	 * entry, then by its identifier ({@see \CCrmRequisiteDetailsComponent}). The setting keeps the entries
	 * in the order they were added, which is not that order.
	 *
	 * @param mixed $settings the serialized column as the ORM answers it: an array once unserialized, and
	 *        anything at all for a preset whose settings were never written.
	 * @return PresetField[]
	 */
	private static function createFields(mixed $settings): array
	{
		$entries = is_array($settings) ? ($settings['FIELDS'] ?? null) : null;
		if (!is_array($entries))
		{
			return [];
		}

		$fields = [];
		foreach ($entries as $entry)
		{
			$field = is_array($entry) ? self::createField($entry) : null;
			if ($field !== null)
			{
				$fields[] = $field;
			}
		}

		usort(
			$fields,
			static fn(PresetField $one, PresetField $other): int
				=> [$one->sort, $one->id] <=> [$other->sort, $other->id],
		);

		return $fields;
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return PresetField|null `null` for an entry that names nothing, which the interface does not show
	 *         either.
	 */
	private static function createField(array $entry): ?PresetField
	{
		$name = isset($entry['FIELD_NAME']) ? (string)$entry['FIELD_NAME'] : '';
		if ($name === '')
		{
			return null;
		}

		$isAddress = $name === self::ADDRESS_MARKER;
		$isUserField = !$isAddress && str_starts_with($name, self::USER_FIELD_PREFIX);

		return new PresetField(
			id: (int)($entry['ID'] ?? 0),
			field: $isAddress || $isUserField ? null : RequisiteColumnMap::findFieldByColumn($name),
			userField: $isUserField ? $name : null,
			isAddress: $isAddress,
			title: isset($entry['FIELD_TITLE']) ? (string)$entry['FIELD_TITLE'] : '',
			inShortList: ($entry['IN_SHORT_LIST'] ?? null) === 'Y',
			sort: (int)($entry['SORT'] ?? 0),
		);
	}
}
