<?php

namespace Bitrix\Mail\Internals;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\ORM;

/**
 * Class MailMassConnectTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_MailMassConnect_Query query()
 * @method static EO_MailMassConnect_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_MailMassConnect_Result getById($id)
 * @method static EO_MailMassConnect_Result getList(array $parameters = [])
 * @method static EO_MailMassConnect_Entity getEntity()
 * @method static \Bitrix\Mail\Internals\EO_MailMassConnect createObject($setDefaultValues = true)
 * @method static \Bitrix\Mail\Internals\EO_MailMassConnect_Collection createCollection()
 * @method static \Bitrix\Mail\Internals\EO_MailMassConnect wakeUpObject($row)
 * @method static \Bitrix\Mail\Internals\EO_MailMassConnect_Collection wakeUpCollection($rows)
 */
class MailMassConnectTable extends DataManager
{
	const MODULE_NAME = 'mail';

	public static function getTableName(): string
	{
		return 'b_mail_mass_connect';
	}

	public static function getMap(): array
	{
		return [
			(new ORM\Fields\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
				->configureTitle('ID')
			,
			(new ORM\Fields\IntegerField('AUTHOR_ID'))
				->configureRequired()
				->configureTitle('Author id')
			,
			(new ORM\Fields\DatetimeField('CREATED_AT'))
				->configureDefaultValue(new DateTime())
				->configureTitle('Mass Connect created at')
			,
			(new ORM\Fields\TextField('SETTINGS_DATA'))
				->configureNullable()
				->configureTitle('Data that was used to start mass connection')
			,
			(new ORM\Fields\TextField('CONNECTION_RESULT'))
				->configureNullable()
				->configureTitle('The result of connecting individual mailboxes')
			,
		];
	}
}
