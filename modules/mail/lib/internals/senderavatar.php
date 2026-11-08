<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internals;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

final class SenderAvatarTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_mail_sender_avatar';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new StringField('EMAIL_NORMALIZED'))
				->configureRequired()
				->configureSize(255)
			,
			new IntegerField('RESOURCE_ID'),
			(new IntegerField('REVISION'))
				->configureRequired()
				->configureDefaultValue(0)
			,
			(new DatetimeField('DATE_MODIFY'))
				->configureRequired()
				->configureDefaultValueNow()
			,
		];
	}
}
