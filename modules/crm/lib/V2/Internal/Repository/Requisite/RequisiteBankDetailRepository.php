<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Requisite;

use Bitrix\Crm\BankDetailTable;
use Bitrix\Crm\EntityBankDetail;
use Bitrix\Crm\RequisiteTable;
use Bitrix\Crm\V2\Internal\Entity\Requisite\RequisiteBankDetail;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\SystemException;

/**
 * The single point the new layer reads the bank details of requisites through.
 *
 * The read is a batch one and only a batch one, for the reason the read of addresses is
 * ({@see RequisiteAddressRepository}): a page of requisites costs a fixed number of queries rather than
 * one per record. The condition is over the owner of the record, in the order the storage indexes it -
 * the identifier of the owner leads (`IX_B_CRM_BANK_DETAIL_15`), and the ordering of the records of one
 * owner follows it in the same index.
 *
 * What makes this read more than a second copy of the read of addresses is that the shape of a record is
 * not the same for every requisite. The storage keeps a column for every bank field the product knows,
 * and a jurisdiction has only a few of them: the country of the preset of the requisite decides which,
 * and a field of another jurisdiction is not an empty field of this record but no field of it at all
 * ({@see RequisiteBankDetail}). So the country of every requisite of the batch is resolved first, and
 * every record is assembled against the composition of its own.
 *
 * **Rights are not checked here**, as in the reads of the same area: the right to read the bank details
 * of a requisite follows from the right to read the owner of that requisite, and it is asked before this
 * class is.
 *
 * Read-only - nothing here writes a record or drops a cache. ORM objects never leave the class.
 *
 * @internal
 */
final class RequisiteBankDetailRepository
{
	/**
	 * The fields every record has, whatever the jurisdiction of its requisite, and the column of
	 * `b_crm_bank_detail` behind each.
	 *
	 * The country the row keeps of its own is not among them: it is written from the preset of the
	 * requisite, so the preset is the one answer to that question and the column is a copy of it.
	 */
	private const COLUMN_BY_FIELD = [
		RequisiteBankDetail::FIELD_ID => 'ID',
		RequisiteBankDetail::FIELD_REQUISITE_ID => 'ENTITY_ID',
		RequisiteBankDetail::FIELD_NAME => 'NAME',
		RequisiteBankDetail::FIELD_ACTIVE => 'ACTIVE',
		RequisiteBankDetail::FIELD_SORT => 'SORT',
		RequisiteBankDetail::FIELD_COMMENTS => 'COMMENTS',
	];

	/**
	 * The fields a jurisdiction decides about, and the column behind each. The columns are the names the
	 * rule of composition speaks ({@see self::availableColumnsOfCountry()}), which is why the map is read
	 * in both directions here.
	 */
	private const BANK_COLUMN_BY_FIELD = [
		RequisiteBankDetail::FIELD_BANK_NAME => 'RQ_BANK_NAME',
		RequisiteBankDetail::FIELD_BANK_ADDRESS => 'RQ_BANK_ADDR',
		RequisiteBankDetail::FIELD_BANK_CODE => 'RQ_BANK_CODE',
		RequisiteBankDetail::FIELD_BANK_ROUTING_NUMBER => 'RQ_BANK_ROUTE_NUM',
		RequisiteBankDetail::FIELD_AGENCY_NAME => 'RQ_AGENCY_NAME',
		RequisiteBankDetail::FIELD_BIK => 'RQ_BIK',
		RequisiteBankDetail::FIELD_MFO => 'RQ_MFO',
		RequisiteBankDetail::FIELD_ACCOUNT_NAME => 'RQ_ACC_NAME',
		RequisiteBankDetail::FIELD_ACCOUNT_NUMBER => 'RQ_ACC_NUM',
		RequisiteBankDetail::FIELD_ACCOUNT_TYPE => 'RQ_ACC_TYPE',
		RequisiteBankDetail::FIELD_ACCOUNT_CURRENCY => 'RQ_ACC_CURRENCY',
		RequisiteBankDetail::FIELD_CORRESPONDENT_ACCOUNT_NUMBER => 'RQ_COR_ACC_NUM',
		RequisiteBankDetail::FIELD_IIK => 'RQ_IIK',
		RequisiteBankDetail::FIELD_IBAN => 'RQ_IBAN',
		RequisiteBankDetail::FIELD_SWIFT => 'RQ_SWIFT',
		RequisiteBankDetail::FIELD_BIC => 'RQ_BIC',
		RequisiteBankDetail::FIELD_CODE_B => 'RQ_CODEB',
		RequisiteBankDetail::FIELD_CODE_G => 'RQ_CODEG',
		RequisiteBankDetail::FIELD_RIB => 'RQ_RIB',
	];

	/**
	 * The jurisdiction a record is read against when the country of its requisite is not one the rule of
	 * composition knows - the same fallback the card of a bank detail applies
	 * ({@see EntityBankDetail::getFormFieldsInfoByCountry()}), so a requisite whose preset names a country
	 * the rule says nothing about shows the same fields in both places.
	 */
	private const DEFAULT_COUNTRY_ID = 122;

	/**
	 * The bank details of the requisites $requisiteIds names, all of them, in one query - and one more
	 * for the countries of their presets, asked only of the requisites that turned out to have records.
	 *
	 * The order is fixed rather than left to the storage: the records of one requisite come back as its
	 * card orders them, by sort and then by identifier, so the same request answers the same order twice.
	 * Grouping the flat answer by {@see RequisiteBankDetail::$requisiteId} is the caller's.
	 *
	 * @param int[] $requisiteIds a value that could not be an identifier is dropped rather than refused,
	 *        as in {@see RequisiteAddressRepository::findByRequisiteIds()}.
	 * @return RequisiteBankDetail[]
	 * @throws SystemException a failure of one of the queries.
	 */
	public function findByRequisiteIds(array $requisiteIds): array
	{
		$ids = array_values(array_unique(array_filter($requisiteIds, static fn(int $id): bool => $id > 0)));
		if ($ids === [])
		{
			return [];
		}

		// Every bank column is read whatever the countries of the batch are: a page may span several
		// jurisdictions, and choosing columns per row is not something one query can do.
		$columns = array_merge(array_values(self::COLUMN_BY_FIELD), array_values(self::BANK_COLUMN_BY_FIELD));

		$rows = BankDetailTable::query()
			->setSelect($columns)
			->where('ENTITY_TYPE_ID', OwnerType::REQUISITE)
			->whereIn('ENTITY_ID', $ids)
			->setOrder([
				'ENTITY_ID' => 'ASC',
				'SORT' => 'ASC',
				'ID' => 'ASC',
			])
			->fetchAll()
		;

		if ($rows === [])
		{
			return [];
		}

		$countryIds = $this->findCountryIdByRequisiteId(
			array_map(static fn(array $row): int => (int)$row['ENTITY_ID'], $rows),
		);

		$availableColumns = [];
		$bankDetails = [];
		foreach ($rows as $row)
		{
			$countryId = $countryIds[(int)$row['ENTITY_ID']] ?? 0;
			$availableColumns[$countryId] ??= self::availableColumnsOfCountry($countryId);
			$bankDetails[] = self::createBankDetail($row, $availableColumns[$countryId]);
		}

		return $bankDetails;
	}

	/**
	 * The country of the preset of every requisite of the batch, by identifier of the requisite, and no
	 * entry at all for a requisite whose preset is gone - nothing keeps a requisite from naming a preset
	 * that was deleted, and such a record is read against the fallback jurisdiction rather than refused.
	 *
	 * @param int[] $requisiteIds
	 * @return array<int, int>
	 * @throws SystemException a failure of the query itself.
	 */
	private function findCountryIdByRequisiteId(array $requisiteIds): array
	{
		$rows = RequisiteTable::query()
			->setSelect(['ID', 'COUNTRY_ID' => 'PRESET.COUNTRY_ID'])
			->whereIn('ID', array_values(array_unique($requisiteIds)))
			->fetchAll()
		;

		$countryIds = [];
		foreach ($rows as $row)
		{
			$countryIds[(int)$row['ID']] = (int)($row['COUNTRY_ID'] ?? 0);
		}

		return $countryIds;
	}

	/**
	 * The bank columns the jurisdiction $countryId has.
	 *
	 * The rule is asked of legacy rather than restated here, and this is the one place the group calls it
	 * from. The composition of the bank details of a requisite belongs to the group of methods of bank
	 * details (task 722733), which is not in the trunk yet; until it is, the rule the product already
	 * applies is the rule, and a second copy of the map of countries would start answering differently
	 * the day a country was added to one of them. When that group lands, this method is what it replaces.
	 *
	 * @return array<string, true>
	 */
	private static function availableColumnsOfCountry(int $countryId): array
	{
		$columnsByCountry = EntityBankDetail::getSingleInstance()->getRqFieldByCountry();
		if (!isset($columnsByCountry[$countryId]))
		{
			$countryId = self::DEFAULT_COUNTRY_ID;
		}

		$columns = [];
		foreach ($columnsByCountry[$countryId] ?? [] as $column)
		{
			$columns[$column] = true;
		}

		return $columns;
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, true> $availableColumns the bank columns of the jurisdiction of the requisite
	 *        this row belongs to; a column outside them is not a field of this record and is `null`.
	 */
	private static function createBankDetail(array $row, array $availableColumns): RequisiteBankDetail
	{
		$fields = [
			RequisiteBankDetail::FIELD_ID => (int)($row['ID'] ?? 0),
			RequisiteBankDetail::FIELD_REQUISITE_ID => (int)($row['ENTITY_ID'] ?? 0),
			RequisiteBankDetail::FIELD_NAME => (string)($row['NAME'] ?? ''),
			// The column keeps a letter and the ORM declares the field boolean, so what comes back depends
			// on the converter having been applied. Both answers say the same thing.
			RequisiteBankDetail::FIELD_ACTIVE => ($row['ACTIVE'] ?? null) === true || ($row['ACTIVE'] ?? null) === 'Y',
			RequisiteBankDetail::FIELD_SORT => (int)($row['SORT'] ?? 0),
			RequisiteBankDetail::FIELD_COMMENTS => (string)($row['COMMENTS'] ?? ''),
		];

		foreach (self::BANK_COLUMN_BY_FIELD as $field => $column)
		{
			$fields[$field] = isset($availableColumns[$column]) ? (string)($row[$column] ?? '') : null;
		}

		// The field names of a bank detail are its property names by contract, so a row becomes a record
		// by name rather than by the order of the maps above.
		return new RequisiteBankDetail(...$fields);
	}
}
