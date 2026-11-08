<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Requisite;

use Bitrix\Crm\RequisiteTable;
use Bitrix\Crm\V2\Internal\Entity\Requisite\Requisite;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

/** @internal */
final class MyCompanyRequisiteRepository
{
	private const COLUMN_BY_FIELD = [
		Requisite::FIELD_ID => 'ID',
		Requisite::FIELD_OWNER_ID => 'ENTITY_ID',
		Requisite::FIELD_OWNER_TYPE_ID => 'ENTITY_TYPE_ID',
		Requisite::FIELD_PRESET_ID => 'PRESET_ID',
		Requisite::FIELD_NAME => 'NAME',
		Requisite::FIELD_ACTIVE => 'ACTIVE',
		Requisite::FIELD_XML_ID => 'XML_ID',
		Requisite::FIELD_ORIGINATOR_ID => 'ORIGINATOR_ID',
		Requisite::FIELD_SORT => 'SORT',
		Requisite::FIELD_CREATED_TIME => 'DATE_CREATE',
		Requisite::FIELD_UPDATED_TIME => 'DATE_MODIFY',
		Requisite::FIELD_CREATED_BY_ID => 'CREATED_BY_ID',
		Requisite::FIELD_UPDATED_BY_ID => 'MODIFY_BY_ID',
		Requisite::FIELD_FULL_NAME => 'RQ_NAME',
		Requisite::FIELD_FIRST_NAME => 'RQ_FIRST_NAME',
		Requisite::FIELD_LAST_NAME => 'RQ_LAST_NAME',
		Requisite::FIELD_SECOND_NAME => 'RQ_SECOND_NAME',
		Requisite::FIELD_COMPANY_TITLE => 'RQ_COMPANY_NAME',
		Requisite::FIELD_COMPANY_FULL_NAME => 'RQ_COMPANY_FULL_NAME',
		Requisite::FIELD_COMPANY_IDENTIFIER => 'RQ_COMPANY_ID',
		Requisite::FIELD_COMPANY_REGISTRATION_DATE => 'RQ_COMPANY_REG_DATE',
		Requisite::FIELD_DIRECTOR_NAME => 'RQ_DIRECTOR',
		Requisite::FIELD_ACCOUNTANT_NAME => 'RQ_ACCOUNTANT',
		Requisite::FIELD_CEO_NAME => 'RQ_CEO_NAME',
		Requisite::FIELD_CEO_POSITION => 'RQ_CEO_WORK_POS',
		Requisite::FIELD_CONTACT_PERSON => 'RQ_CONTACT',
		Requisite::FIELD_EMAIL_ADDRESS => 'RQ_EMAIL',
		Requisite::FIELD_PHONE_NUMBER => 'RQ_PHONE',
		Requisite::FIELD_FAX_NUMBER => 'RQ_FAX',
		Requisite::FIELD_IDENTIFICATION_TYPE_ID => 'RQ_IDENT_TYPE',
		Requisite::FIELD_IDENTITY_DOCUMENT_TYPE => 'RQ_IDENT_DOC',
		Requisite::FIELD_IDENTITY_DOCUMENT_SERIES => 'RQ_IDENT_DOC_SER',
		Requisite::FIELD_IDENTITY_DOCUMENT_NUMBER => 'RQ_IDENT_DOC_NUM',
		Requisite::FIELD_IDENTITY_DOCUMENT_PERSONAL_NUMBER => 'RQ_IDENT_DOC_PERS_NUM',
		Requisite::FIELD_IDENTITY_DOCUMENT_ISSUE_DATE => 'RQ_IDENT_DOC_DATE',
		Requisite::FIELD_IDENTITY_DOCUMENT_ISSUED_BY => 'RQ_IDENT_DOC_ISSUED_BY',
		Requisite::FIELD_IDENTITY_DOCUMENT_DEPARTMENT_CODE => 'RQ_IDENT_DOC_DEP_CODE',
		Requisite::FIELD_INN => 'RQ_INN',
		Requisite::FIELD_KPP => 'RQ_KPP',
		Requisite::FIELD_USRLE => 'RQ_USRLE',
		Requisite::FIELD_IFNS => 'RQ_IFNS',
		Requisite::FIELD_OGRN => 'RQ_OGRN',
		Requisite::FIELD_OGRNIP => 'RQ_OGRNIP',
		Requisite::FIELD_OKPO => 'RQ_OKPO',
		Requisite::FIELD_OKTMO => 'RQ_OKTMO',
		Requisite::FIELD_OKVED => 'RQ_OKVED',
		Requisite::FIELD_EDRPOU => 'RQ_EDRPOU',
		Requisite::FIELD_DRFO => 'RQ_DRFO',
		Requisite::FIELD_KBE => 'RQ_KBE',
		Requisite::FIELD_IIN => 'RQ_IIN',
		Requisite::FIELD_BIN => 'RQ_BIN',
		Requisite::FIELD_REGON => 'RQ_REGON',
		Requisite::FIELD_KRS => 'RQ_KRS',
		Requisite::FIELD_PESEL => 'RQ_PESEL',
		Requisite::FIELD_SIRET => 'RQ_SIRET',
		Requisite::FIELD_SIREN => 'RQ_SIREN',
		Requisite::FIELD_RCS => 'RQ_RCS',
		Requisite::FIELD_CNPJ => 'RQ_CNPJ',
		Requisite::FIELD_CPF => 'RQ_CPF',
		Requisite::FIELD_STATE_REGISTRATION => 'RQ_STATE_REG',
		Requisite::FIELD_MUNICIPAL_REGISTRATION => 'RQ_MNPL_REG',
		Requisite::FIELD_LEGAL_FORM => 'RQ_LEGAL_FORM',
		Requisite::FIELD_SHARE_CAPITAL => 'RQ_CAPITAL',
		Requisite::FIELD_TAX_REGIME_ID => 'RQ_TAX_REGIME',
		Requisite::FIELD_RESIDENCE_COUNTRY => 'RQ_RESIDENCE_COUNTRY',
		Requisite::FIELD_BASIS_DOCUMENT => 'RQ_BASE_DOC',
		Requisite::FIELD_REGISTRATION_CERTIFICATE_SERIES => 'RQ_ST_CERT_SER',
		Requisite::FIELD_REGISTRATION_CERTIFICATE_NUMBER => 'RQ_ST_CERT_NUM',
		Requisite::FIELD_REGISTRATION_CERTIFICATE_DATE => 'RQ_ST_CERT_DATE',
		Requisite::FIELD_VAT_PAYER => 'RQ_VAT_PAYER',
		Requisite::FIELD_VAT_NUMBER => 'RQ_VAT_ID',
		Requisite::FIELD_VAT_CERTIFICATE_SERIES => 'RQ_VAT_CERT_SER',
		Requisite::FIELD_VAT_CERTIFICATE_NUMBER => 'RQ_VAT_CERT_NUM',
		Requisite::FIELD_VAT_CERTIFICATE_DATE => 'RQ_VAT_CERT_DATE',
	];

	private const INT_FIELDS = [
		Requisite::FIELD_ID => true,
		Requisite::FIELD_OWNER_ID => true,
		Requisite::FIELD_OWNER_TYPE_ID => true,
		Requisite::FIELD_PRESET_ID => true,
		Requisite::FIELD_SORT => true,
		Requisite::FIELD_CREATED_BY_ID => true,
		Requisite::FIELD_UPDATED_BY_ID => true,
	];

	private const BOOL_FIELDS = [
		Requisite::FIELD_ACTIVE => true,
		Requisite::FIELD_VAT_PAYER => true,
	];

	private const DATE_TIME_FIELDS = [
		Requisite::FIELD_CREATED_TIME => true,
		Requisite::FIELD_UPDATED_TIME => true,
	];

	/**
	 * @param int[] $companyIds
	 * @param string[]|null $selectedFields
	 * @return array<int, Requisite[]>
	 */
	public function findAllByCompanyIds(array $companyIds, ?array $selectedFields = null): array
	{
		$companyIds = array_values(array_unique(array_filter(
			array_map(static fn(mixed $id): int => (int)$id, $companyIds),
			static fn(int $id): bool => $id > 0,
		)));
		if ($companyIds === [])
		{
			return [];
		}

		$columns = $selectedFields === null
			? array_values(self::COLUMN_BY_FIELD)
			: array_values(array_intersect_key(self::COLUMN_BY_FIELD, array_flip($selectedFields)));
		$columns = array_values(array_unique([
			self::COLUMN_BY_FIELD[Requisite::FIELD_ID],
			self::COLUMN_BY_FIELD[Requisite::FIELD_OWNER_ID],
			self::COLUMN_BY_FIELD[Requisite::FIELD_OWNER_TYPE_ID],
			...$columns,
		]));

		$rows = RequisiteTable::query()
			->setSelect($columns)
			->where('ENTITY_TYPE_ID', OwnerType::COMPANY)
			->whereIn('ENTITY_ID', $companyIds)
			->setOrder(['ID' => 'ASC'])
			->exec()
		;

		$grouped = [];
		while ($row = $rows->fetch())
		{
			$requisite = $this->createRequisite($row);
			$ownerId = (int)($row['ENTITY_ID'] ?? 0);
			if ($ownerId > 0)
			{
				$grouped[$ownerId][] = $requisite;
			}
		}

		return $grouped;
	}

	/** @param array<string, mixed> $row */
	private function createRequisite(array $row): Requisite
	{
		$fields = [];
		foreach (self::COLUMN_BY_FIELD as $field => $column)
		{
			$fields[$field] = $this->castValue($field, $row[$column] ?? null);
		}
		$fields[Requisite::FIELD_ID] = (int)$fields[Requisite::FIELD_ID];

		return new Requisite(...$fields);
	}

	private function castValue(string $field, mixed $value): mixed
	{
		if ($value === null)
		{
			return null;
		}
		if (isset(self::INT_FIELDS[$field]))
		{
			return (int)$value;
		}
		if (isset(self::BOOL_FIELDS[$field]))
		{
			return $value === true || $value === 'Y';
		}
		if (isset(self::DATE_TIME_FIELDS[$field]))
		{
			if (!$value instanceof DateTime)
			{
				throw new SystemException(sprintf(
					'Unexpected value of the requisite field `%s`: %s',
					$field,
					get_debug_type($value),
				));
			}

			return $value;
		}

		return (string)$value;
	}
}
