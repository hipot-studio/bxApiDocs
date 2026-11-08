<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\Comment;

use Bitrix\Disk\Internals\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

final class CommentTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_disk_flipchart_comment';
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
			(new IntegerField('COMMENT_ID'))
				->configureRequired()
			,
			(new StringField('AUTHOR_ID'))
				->configureBinary()
				->configureRequired()
			,
			(new TextField('CONTENT'))
				->configureLong()
				->configureRequired()
				->addSaveDataModifier(self::escapeFourByteCharacters(...))
				->addFetchDataModifier(self::unescapeFourByteCharacters(...))
			,
			(new TextField('ATTACHED'))
				->configureLong()
				->configureNullable()
			,
			(new DatetimeField('CREATED_AT'))
				->configureRequired()
			,
			(new DatetimeField('EDITED_AT'))
				->configureNullable()
			,
			(new DatetimeField('DELETED_AT'))
				->configureNullable()
			,
		];
	}

	/**
	 * The form of {@see FourByteText}, applied only to strings: an SQL expression goes through as it
	 * is. What was stored before this text was moved out of here is read back unchanged.
	 */
	private static function escapeFourByteCharacters(mixed $value): mixed
	{
		return is_string($value) ? FourByteText::encode($value) : $value;
	}

	private static function unescapeFourByteCharacters(mixed $value): mixed
	{
		return is_string($value) ? FourByteText::decode($value) : $value;
	}
}
