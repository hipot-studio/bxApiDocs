<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\Comment;

use Bitrix\Disk\Internals\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

/**
 * Read boundary of one registered user in one thread: the contract number of the last comment the
 * user has read. A guest keeps the same state in temporary storage, so no row here belongs to one.
 */
final class CommentReadTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_disk_flipchart_comment_read';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new StringField('THREAD_ID'))
				->configureBinary()
				->configureRequired()
				->configureSize(36)
			,
			(new StringField('USER_ID'))
				->configureBinary()
				->configureRequired()
				->configureSize(32)
			,
			(new IntegerField('LAST_READ_COMMENT_ID'))
				->configureRequired()
			,
			(new DatetimeField('UPDATED_AT'))
				->configureRequired()
			,
		];
	}
}
