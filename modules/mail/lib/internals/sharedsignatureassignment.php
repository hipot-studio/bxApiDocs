<?php

namespace Bitrix\Mail\Internals;

use Bitrix\Mail\Internals\Entity\SharedSignatureAssignment;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\Entity;

/**
 * Class SharedSignatureAssignmentTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_SharedSignatureAssignment_Query query()
 * @method static EO_SharedSignatureAssignment_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_SharedSignatureAssignment_Result getById($id)
 * @method static EO_SharedSignatureAssignment_Result getList(array $parameters = [])
 * @method static EO_SharedSignatureAssignment_Entity getEntity()
 * @method static \Bitrix\Mail\Internals\Entity\SharedSignatureAssignment createObject($setDefaultValues = true)
 * @method static \Bitrix\Mail\Internals\EO_SharedSignatureAssignment_Collection createCollection()
 * @method static \Bitrix\Mail\Internals\Entity\SharedSignatureAssignment wakeUpObject($row)
 * @method static \Bitrix\Mail\Internals\EO_SharedSignatureAssignment_Collection wakeUpCollection($rows)
 */
class SharedSignatureAssignmentTable extends DataManager
{
	const TARGET_ALL = 'all';
	const TARGET_MAILBOX = 'mailbox';
	const TARGET_DEPARTMENT = 'department';
	const TARGET_USER = 'user';

	/**
	 * Transitional target: a raw sender string ("Name <box@example.com>" or a bare address)
	 * carried over from the personal-signature binding. It is matched against the sender at
	 * read time instead of being expanded into mailboxes, and disappears once the bindings
	 * are converted to identifiers. The string itself lives in TARGET_VALUE, TARGET_ID stays 0.
	 */
	const TARGET_SENDER = 'sender';

	/**
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_mail_shared_signature_assignment';
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
			new Entity\IntegerField('SIGNATURE_ID', [
				'required' => true,
			]),
			new Entity\StringField('TARGET_TYPE', [
				'required' => true,
			]),
			new Entity\IntegerField('TARGET_ID', [
				'required' => true,
				'default_value' => 0,
			]),
			new Entity\StringField('TARGET_VALUE'),
			new Entity\BooleanField('IS_FLAT', [
				'values' => ['N', 'Y'],
				'default_value' => 'N',
			]),
			new Entity\DatetimeField('DATE_CREATE', [
				'required' => true,
			]),
		];
	}

	/**
	 * @return \Bitrix\Main\ORM\Objectify\EntityObject|string
	 */
	public static function getObjectClass()
	{
		return SharedSignatureAssignment::class;
	}
}
