<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\Comment;

use Bitrix\Disk\Internals\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

/**
 * The "everything is read" mark of one registered user on one board. It carries the largest comment
 * row the board held when the mark was set, so a comment added later makes the mark stale by
 * comparison instead of by walking over the users who hold one.
 */
final class CommentReadAllTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_disk_flipchart_comment_read_all';
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
			(new StringField('USER_ID'))
				->configureBinary()
				->configureRequired()
				->configureSize(32)
			,
			(new IntegerField('MAX_COMMENT_ROW_ID'))
				->configureRequired()
			,
			(new DatetimeField('UPDATED_AT'))
				->configureRequired()
			,
		];
	}
}
