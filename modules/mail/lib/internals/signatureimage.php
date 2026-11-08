<?php

namespace Bitrix\Mail\Internals;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\UuidGenerator;

class SignatureImageTable extends DataManager
{
	public const STATE_PENDING = 'pending';
	public const STATE_ATTACHED = 'attached';
	public const STATE_ORPHANED = 'orphaned';
	public const STATE_DELETING = 'deleting';

	public static function getTableName(): string
	{
		return 'b_mail_signature_image';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new StringField('UUID'))
				->configureRequired()
				->configureSize(36)
				->configureDefaultValue(
					static fn(): string => UuidGenerator::generateV4(),
				)
			,
			(new IntegerField('FILE_ID'))
				->configureRequired()
			,
			(new IntegerField('OWNER_ID'))
				->configureRequired()
			,
			(new StringField('CONTENT_TYPE'))
				->configureRequired()
				->configureSize(32)
			,
			(new IntegerField('WIDTH'))
				->configureRequired()
			,
			(new IntegerField('HEIGHT'))
				->configureRequired()
			,
			(new IntegerField('FILE_SIZE'))
				->configureRequired()
			,
			(new BooleanField('IS_ANIMATED'))
				->configureRequired()
				->configureValues('N', 'Y')
				->configureDefaultValue('N')
			,
			(new StringField('STATE'))
				->configureRequired()
				->configureSize(16)
				->configureDefaultValue(self::STATE_PENDING)
			,
			(new DatetimeField('DATE_CREATE'))
				->configureRequired()
				->configureDefaultValueNow()
			,
			(new DatetimeField('DATE_MODIFY'))
				->configureRequired()
				->configureDefaultValueNow()
			,
		];
	}
}
