<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\Annotation;

use Bitrix\Disk\Document\Flipchart\Comment\FourByteText;
use Bitrix\Disk\Internals\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\FloatField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

final class AnnotationTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_disk_flipchart_annotation';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new StringField('DOCUMENT_ID', ['binary' => true]))
				->configureRequired()
				->configureSize(255)
			,
			(new IntegerField('OBJECT_ID'))
				->configureRequired()
			,
			(new IntegerField('VERSION_ID'))
				->configureNullable()
			,
			(new StringField('ANNOTATION_ID', ['binary' => true]))
				->configureRequired()
				->configureSize(36)
			,
			(new StringField('PARENT_ID', ['binary' => true]))
				->configureRequired()
				->configureSize(26)
			,
			(new StringField('EDGE', ['binary' => true]))
				->configureRequired()
				->configureSize(6)
			,
			(new FloatField('POSITION_T'))
				->configureRequired()
			,
			(new TextField('CONTENT'))
				->configureRequired()
				->addSaveDataModifier(self::escapeFourByteCharacters(...))
				->addFetchDataModifier(self::unescapeFourByteCharacters(...))
			,
			(new StringField('AUTHOR_ID', ['binary' => true]))
				->configureRequired()
			,
			(new DatetimeField('CREATED_AT'))
				->configureRequired()
			,
			(new DatetimeField('UPDATED_AT'))
				->configureNullable()
			,
			(new DatetimeField('DELETED_AT'))
				->configureNullable()
			,
			(new StringField('DELETE_REASON', ['binary' => true]))
				->configureNullable()
				->configureSize(14)
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
