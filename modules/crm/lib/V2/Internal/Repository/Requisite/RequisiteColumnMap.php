<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Requisite;

use Bitrix\Crm\V2\Internal\Entity\Requisite\Requisite;
use Bitrix\Crm\V2\Internal\Entity\Requisite\RequisiteWriteData;
use Bitrix\Main\ArgumentException;

/**
 * The one place that knows which column of `b_crm_requisite` is behind a field of a requisite, and of
 * what storage type.
 *
 * The read and the write of the area both need this, and each of them holding its own copy is the one
 * failure this class exists to prevent: a column renamed or a field added on one side alone would leave
 * the two answering different questions about the same record, and nothing would say so until a value
 * went missing.
 *
 * The vocabulary is the field names of {@see Requisite} - the names the request mapper, the published
 * DTO and the internal types all speak - plus the few names of {@see RequisiteWriteData} that only the
 * write knows. Storage names never leave the repositories of the area.
 *
 * @internal
 */
final class RequisiteColumnMap
{
	/** Every field of a requisite record and the column of `b_crm_requisite` behind it. */
	public const COLUMN_BY_FIELD = [
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

	/**
	 * The columns only the write of the area addresses: the internal write mode, the two file fields and
	 * the service code of an exchange. None of them is a field of the read contract - the write mode is
	 * the shape of the write rather than a property of a requisite, and the other three are not published
	 * at all - but the write has to read the current value of them and the facade line has to set them.
	 */
	public const COLUMN_BY_WRITE_ONLY_FIELD = [
		RequisiteWriteData::FIELD_ADDRESS_ONLY => 'ADDRESS_ONLY',
		RequisiteWriteData::FIELD_SIGNATURE_FILE_ID => 'RQ_SIGNATURE',
		RequisiteWriteData::FIELD_STAMP_FILE_ID => 'RQ_STAMP',
		RequisiteWriteData::FIELD_CODE => 'CODE',
	];

	/**
	 * The fields no composition of any preset ever mentions: the properties a requisite has as a record
	 * of the portal rather than as a statement about a jurisdiction.
	 *
	 * The vocabulary of the area splits in two, and only one half is something a preset decides about. A
	 * preset says which legal details of which country a requisite created on it means anything by - and
	 * an administrator adds those to a preset and drops them from it. The identifier of the record, its
	 * owner and the type of that owner, the preset it names, its title, its activity, its external
	 * identifier and originator, its order among the requisites of the owner, the moments and the authors
	 * of the record, the service code of an exchange and the internal write mode of an address-only
	 * requisite are not of that half: they are what a requisite is as a record, the same under every
	 * preset of every country.
	 *
	 * Declared as the half that is closed, rather than as the half a preset decides about: a field added
	 * to the area from now on is a legal detail of some country - that is what the storage keeps cells
	 * for - so counting it as one a preset decides about is right by default, and counting it as a
	 * property of the record would quietly drop it out of a check instead.
	 *
	 * @see self::isFieldOfPreset()
	 */
	private const FIELDS_OUTSIDE_ANY_PRESET = [
		Requisite::FIELD_ID => true,
		Requisite::FIELD_OWNER_ID => true,
		Requisite::FIELD_OWNER_TYPE_ID => true,
		Requisite::FIELD_PRESET_ID => true,
		Requisite::FIELD_NAME => true,
		Requisite::FIELD_ACTIVE => true,
		Requisite::FIELD_XML_ID => true,
		Requisite::FIELD_ORIGINATOR_ID => true,
		Requisite::FIELD_SORT => true,
		Requisite::FIELD_CREATED_TIME => true,
		Requisite::FIELD_UPDATED_TIME => true,
		Requisite::FIELD_CREATED_BY_ID => true,
		Requisite::FIELD_UPDATED_BY_ID => true,
		RequisiteWriteData::FIELD_ADDRESS_ONLY => true,
		RequisiteWriteData::FIELD_CODE => true,
	];

	// The three maps below are keyed by field name rather than listed: every field of every row of a page
	// is classified through them, and a page holds up to a thousand records of seventy-four fields. They
	// cover the whole vocabulary of the area, the write-only names included, so a caller that only knows
	// part of it simply never asks about the rest.

	public const INT_FIELDS = [
		Requisite::FIELD_ID => true,
		Requisite::FIELD_OWNER_ID => true,
		Requisite::FIELD_OWNER_TYPE_ID => true,
		Requisite::FIELD_PRESET_ID => true,
		Requisite::FIELD_SORT => true,
		Requisite::FIELD_CREATED_BY_ID => true,
		Requisite::FIELD_UPDATED_BY_ID => true,
		RequisiteWriteData::FIELD_SIGNATURE_FILE_ID => true,
		RequisiteWriteData::FIELD_STAMP_FILE_ID => true,
	];

	public const BOOL_FIELDS = [
		Requisite::FIELD_ACTIVE => true,
		Requisite::FIELD_VAT_PAYER => true,
		RequisiteWriteData::FIELD_ADDRESS_ONLY => true,
	];

	public const DATE_TIME_FIELDS = [
		Requisite::FIELD_CREATED_TIME => true,
		Requisite::FIELD_UPDATED_TIME => true,
	];

	/**
	 * The value a boolean field keeps when the caller named no value of its own. Legacy replaces a value
	 * that is not strictly one of the four it accepts with exactly this
	 * ({@see \Bitrix\Crm\EntityRequisite::add()}), and that is observable behaviour of the write rather
	 * than a detail of the storage, so it is stated here instead of being left to the ORM defaults.
	 */
	public const BOOL_FIELD_DEFAULT = [
		Requisite::FIELD_ACTIVE => true,
		Requisite::FIELD_VAT_PAYER => false,
		RequisiteWriteData::FIELD_ADDRESS_ONLY => false,
	];

	/** @var array<string, string>|null */
	private static ?array $columnByWriteField = null;

	/** @var array<string, string>|null */
	private static ?array $fieldByColumn = null;

	/**
	 * @throws ArgumentException a name that is not a field of a requisite.
	 */
	public static function requireColumn(string $field): string
	{
		$column = self::COLUMN_BY_FIELD[$field] ?? null;
		if ($column === null)
		{
			throw new ArgumentException(sprintf('Unknown requisite field `%s`', $field), 'field');
		}

		return $column;
	}

	/**
	 * The column a write addresses $field through.
	 *
	 * The identifier is refused rather than mapped: a requisite is addressed by the argument of the
	 * write, never by a field of the set it writes, so an identifier inside the set is a caller that
	 * believes it can move a record between primary keys.
	 *
	 * @throws ArgumentException a name that is not a field of a requisite, or the identifier.
	 */
	public static function requireWriteColumn(string $field): string
	{
		if ($field === Requisite::FIELD_ID)
		{
			throw new ArgumentException(
				'A requisite is addressed by its identifier rather than writing it as a field',
				'field',
			);
		}

		$column = self::columnByWriteField()[$field] ?? null;
		if ($column === null)
		{
			throw new ArgumentException(sprintf('Unknown requisite field `%s`', $field), 'field');
		}

		return $column;
	}

	/**
	 * The field of the area $column belongs to, and `null` for a name that is no column of it.
	 *
	 * The map read backwards, because one caller holds the storage name and needs the field: a preset
	 * keeps the names of the columns it shows, and what leaves
	 * {@see \Bitrix\Crm\V2\Internal\Repository\Requisite\PresetRepository} has to be the field name of the
	 * contract. Reading it here rather than flipping the map at the call site is what keeps the map
	 * declared once and turned around once - and it is turned around whole, the write-only columns
	 * included, because a preset may well show the signature and the stamp.
	 *
	 * One column stands behind one field, which is what makes the map reversible at all; a second field
	 * over the same column would silently lose one of the two.
	 */
	public static function findFieldByColumn(string $column): ?string
	{
		self::$fieldByColumn ??= array_flip(self::COLUMN_BY_FIELD + self::COLUMN_BY_WRITE_ONLY_FIELD);

		return self::$fieldByColumn[$column] ?? null;
	}

	/**
	 * Whether a preset is what decides that $field applies at all - which is the only kind of field the
	 * membership of a preset can be asked about ({@see \Bitrix\Crm\V2\Internal\Entity\Requisite\Preset::hasField()}).
	 *
	 * A name the area does not know is not one of them either: nothing that is no field of a requisite is
	 * a field a preset shows.
	 */
	public static function isFieldOfPreset(string $field): bool
	{
		if (isset(self::FIELDS_OUTSIDE_ANY_PRESET[$field]))
		{
			return false;
		}

		return isset(self::COLUMN_BY_FIELD[$field]) || isset(self::COLUMN_BY_WRITE_ONLY_FIELD[$field]);
	}

	/**
	 * Every field a write of the area may address and the column behind it.
	 *
	 * @return array<string, string>
	 */
	public static function columnByWriteField(): array
	{
		self::$columnByWriteField ??=
			array_diff_key(self::COLUMN_BY_FIELD, [Requisite::FIELD_ID => true])
			+ self::COLUMN_BY_WRITE_ONLY_FIELD
		;

		return self::$columnByWriteField;
	}
}
