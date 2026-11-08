<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internals;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;

final class ScheduledMessageAttachmentTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_mail_scheduled_message_attachment';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('SCHEDULED_MESSAGE_ID'))
				->configureRequired()
			,
			(new IntegerField('FILE_ID'))
				->configureRequired()
			,
			(new IntegerField('SOURCE_OBJECT_ID'))
				->configureDefaultValue(0)
			,
			(new StringField('FILE_NAME'))
				->configureSize(255)
				->configureRequired()
			,
			(new StringField('CONTENT_ID'))
				->configureSize(255)
			,
			(new BooleanField('IS_INLINE'))
				->configureValues('N', 'Y')
				->configureRequired()
				->configureDefaultValue(false)
			,
			new Reference(
				'SCHEDULED_MESSAGE',
				ScheduledMessageTable::class,
				Join::on('this.SCHEDULED_MESSAGE_ID', 'ref.ID'),
			),
		];
	}
}
