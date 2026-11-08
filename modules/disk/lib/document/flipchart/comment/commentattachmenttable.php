<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\Comment;

use Bitrix\Disk\Internals\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

/**
 * Metadata of one uploaded attachment: the public path, the board and the verified user it was
 * uploaded by. An upload stays temporary until `COMMENT_ROW_ID` names the comment it was first
 * attached to; a row that never gets one is what the cleanup agent removes together with its file.
 */
final class CommentAttachmentTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_disk_flipchart_comment_attachment';
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
			(new IntegerField('FILE_ID'))
				->configureRequired()
			,
			(new StringField('PATH'))
				->configureBinary()
				->configureRequired()
				->configureSize(255)
			,
			(new StringField('OWNER_ID'))
				->configureBinary()
				->configureRequired()
				->configureSize(32)
			,
			(new IntegerField('COMMENT_ROW_ID'))
				->configureNullable()
			,
			(new DatetimeField('CREATED_AT'))
				->configureRequired()
			,
		];
	}
}
