<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\Comment;

use Bitrix\Disk\Internals\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\FloatField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

final class CommentThreadTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_disk_flipchart_comment_thread';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('OBJECT_ID'))
				->configureRequired()
			,
			(new StringField('DOCUMENT_ID'))
				->configureBinary()
				->configureRequired()
			,
			(new StringField('THREAD_ID'))
				->configureBinary()
				->configureRequired()
				->configureSize(36)
			,
			(new StringField('AUTHOR_ID'))
				->configureBinary()
				->configureRequired()
			,
			(new FloatField('COORD_X'))
				->configureRequired()
			,
			(new FloatField('COORD_Y'))
				->configureRequired()
			,
			(new StringField('PARENT_ID'))
				->configureBinary()
				->configureNullable()
				->configureSize(26)
			,
			(new FloatField('WIDTH'))
				->configureNullable()
			,
			(new FloatField('HEIGHT'))
				->configureNullable()
			,
			(new DatetimeField('CREATED_AT'))
				->configureRequired()
			,
			(new DatetimeField('RESOLVED_AT'))
				->configureNullable()
			,
			(new StringField('RESOLVED_BY'))
				->configureBinary()
				->configureNullable()
			,
			(new DatetimeField('DELETED_AT'))
				->configureNullable()
			,
		];
	}
}
