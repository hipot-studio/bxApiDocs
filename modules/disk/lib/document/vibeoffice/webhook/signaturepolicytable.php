<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Webhook;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\ArrayField;
use Bitrix\Main\ORM\Fields\StringField;

final class SignaturePolicyTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_disk_vo_webhook_policy';
	}

	public static function getMap(): array
	{
		return [
			(new StringField('BINDING_ID'))->configureSize(64)->configurePrimary()->configureRequired(),
			(new StringField('PLATFORM_ID'))->configureSize(36)->configureRequired(),
			(new StringField('HOST_ID'))->configureSize(64)->configureRequired(),
			(new IntegerField('RECEIVE_MIN'))->configureDefaultValue(1)->configureRequired(),
			(new StringField('TRANSITION_ID'))->configureSize(36)->configureNullable(),
			(new IntegerField('SENDER_EPOCH'))->configureSize(8)->configureDefaultValue(0)->configureRequired(),
			(new StringField('CONTRACT_ID'))->configureSize(32)->configureRequired(),
			(new StringField('PHASE'))->configureSize(16)->configureDefaultValue('legacy')->configureRequired(),
			(new ArrayField('KEY_FENCE'))->configureSerializationJson()->configureNullable(),
		];
	}
}
