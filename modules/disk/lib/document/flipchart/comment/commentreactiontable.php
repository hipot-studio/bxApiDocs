<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\Comment;

use Bitrix\Disk\Internals\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

/**
 * One reaction of one registered user on one comment. The unique key over the comment, the user
 * and the emoji is what makes a repeated add idempotent, so the emoji is kept as the exact byte
 * sequence of the request: a value written, filtered and compared in one and the same form.
 */
final class CommentReactionTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_disk_flipchart_comment_reaction';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('COMMENT_ROW_ID'))
				->configureRequired()
			,
			(new StringField('USER_ID'))
				->configureBinary()
				->configureRequired()
				->configureSize(32)
			,
			(new StringField('EMOJI'))
				->configureBinary()
				->configureRequired()
				->configureSize(320)
			,
			(new DatetimeField('CREATED_AT'))
				->configureRequired()
			,
		];
	}
}
