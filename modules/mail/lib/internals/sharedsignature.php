<?php

namespace Bitrix\Mail\Internals;

use Bitrix\Mail\Internals\Entity\SharedSignature;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\Entity;

/**
 * Class SharedSignatureTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_SharedSignature_Query query()
 * @method static EO_SharedSignature_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_SharedSignature_Result getById($id)
 * @method static EO_SharedSignature_Result getList(array $parameters = [])
 * @method static EO_SharedSignature_Entity getEntity()
 * @method static \Bitrix\Mail\Internals\Entity\SharedSignature createObject($setDefaultValues = true)
 * @method static \Bitrix\Mail\Internals\EO_SharedSignature_Collection createCollection()
 * @method static \Bitrix\Mail\Internals\Entity\SharedSignature wakeUpObject($row)
 * @method static \Bitrix\Mail\Internals\EO_SharedSignature_Collection wakeUpCollection($rows)
 */
class SharedSignatureTable extends DataManager
{
	/** Visible to the owner only — the former personal signature. */
	const SCOPE_OWNER = 'owner';

	/** Visible to everyone who uses the assigned mailboxes — the former corporate signature. */
	const SCOPE_SHARED = 'shared';

	/**
	 * @return string[]
	 */
	public static function getScopes(): array
	{
		return [self::SCOPE_OWNER, self::SCOPE_SHARED];
	}

	/**
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_mail_shared_signature';
	}

	/**
	 * @return array
	 */
	public static function getMap()
	{
		return [
			new Entity\IntegerField('ID', [
				'primary' => true,
				'autocomplete' => true,
			]),
			new Entity\IntegerField('CREATED_BY', [
				'required' => true,
			]),
			new Entity\IntegerField('OWNER_ID', [
				'required' => true,
				'default_value' => 0,
			]),
			new Entity\StringField('SCOPE', [
				'required' => true,
				'default_value' => self::SCOPE_SHARED,
			]),
			new Entity\TextField('SIGNATURE'),
			// Row of the legacy personal-signature table this signature was copied from. Empty for
			// signatures created straight in the unified model. A unique index over the column is
			// what makes the migration idempotent, see SignatureMigrator.
			// Nullable on purpose: a unique index tolerates any number of NULLs but only one 0.
			new Entity\IntegerField('LEGACY_ID', [
				'nullable' => true,
			]),
			new Entity\DatetimeField('DATE_CREATE', [
				'required' => true,
			]),
			new Entity\DatetimeField('DATE_MODIFY'),
		];
	}

	/**
	 * @return \Bitrix\Main\ORM\Objectify\EntityObject|string
	 */
	public static function getObjectClass()
	{
		return SharedSignature::class;
	}
}
