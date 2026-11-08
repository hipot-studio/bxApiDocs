<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Models;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\Type\DateTime;

final class PlatformSessionTable extends DataManager
{
	public const STATUS_PENDING = 'pending';
	public const STATUS_ACTIVE = 'active';
	public const STATUS_TERMINATED = 'terminated';

	public static function getTableName(): string
	{
		return 'b_disk_vo_session';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new StringField('HOST_DOC_KEY'))->configureRequired()->configureSize(128),
			(new StringField('API_SESSION_ID'))->configureNullable()->configureSize(36),
			(new IntegerField('DOCUMENT_SESSION_ID'))->configureNullable(),
			(new StringField('OPEN_TOKEN'))->configureNullable()->configureSize(36),
			(new StringField('EDITOR_KEY'))->configureNullable()->configureSize(255),
			(new IntegerField('REV'))->configureNullable(),
			(new StringField('STATUS'))->configureRequired()->configureSize(16),
			(new DatetimeField('CREATE_TIME'))->configureDefaultValue(static fn() => new DateTime()),
			(new DatetimeField('UPDATE_TIME'))->configureDefaultValue(static fn() => new DateTime()),
		];
	}
}
