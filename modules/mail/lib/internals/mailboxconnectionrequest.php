<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internals;

use Bitrix\Mail\Helper\Enum\MailboxConnectionRequestStatus;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\ORM;


/**
 * Class MailboxConnectionRequestTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_MailboxConnectionRequest_Query query()
 * @method static EO_MailboxConnectionRequest_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_MailboxConnectionRequest_Result getById($id)
 * @method static EO_MailboxConnectionRequest_Result getList(array $parameters = [])
 * @method static EO_MailboxConnectionRequest_Entity getEntity()
 * @method static \Bitrix\Mail\Internals\EO_MailboxConnectionRequest createObject($setDefaultValues = true)
 * @method static \Bitrix\Mail\Internals\EO_MailboxConnectionRequest_Collection createCollection()
 * @method static \Bitrix\Mail\Internals\EO_MailboxConnectionRequest wakeUpObject($row)
 * @method static \Bitrix\Mail\Internals\EO_MailboxConnectionRequest_Collection wakeUpCollection($rows)
 */
class MailboxConnectionRequestTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_mail_mailbox_connection_request';
	}

	public static function getMap(): array
	{
		return [
			(new ORM\Fields\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
				->configureTitle('ID')
			,
			(new ORM\Fields\IntegerField('REQUESTER_ID'))
				->configureRequired()
				->configureTitle('Requester user id')
			,
			(new ORM\Fields\StringField('COMMENT'))
				->configureNullable()
				->configureSize(100)
				->configureTitle('Comment from requester')
			,
			(new ORM\Fields\EnumField('STATUS'))
				->configureRequired()
				->configureValues(MailboxConnectionRequestStatus::values())
				->configureDefaultValue(MailboxConnectionRequestStatus::Pending->value)
				->configureTitle('Request status')
			,
			(new ORM\Fields\IntegerField('ADMIN_ID'))
				->configureNullable()
				->configureTitle('Admin who processed the request')
			,
			(new ORM\Fields\IntegerField('MAILBOX_ID'))
				->configureNullable()
				->configureTitle('Connected mailbox id')
			,
			(new ORM\Fields\IntegerField('CHAT_ID'))
				->configureNullable()
				->configureTitle('IM chat id for the request')
			,
			(new ORM\Fields\DatetimeField('CREATED_AT'))
				->configureDefaultValue(new DateTime())
				->configureTitle('Request created at')
			,
			(new ORM\Fields\DatetimeField('UPDATED_AT'))
				->configureDefaultValue(new DateTime())
				->configureTitle('Request updated at')
			,
		];
	}
}
