<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internals;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\StringField;

final class DraftAttachmentTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_mail_draft_attachment';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new IntegerField('DRAFT_ID'))->configureRequired(),
			(new IntegerField('FILE_ID'))->configureRequired(),
			(new IntegerField('SOURCE_OBJECT_ID'))->configureRequired(),
			(new IntegerField('SOURCE_FILE_ID'))->configureRequired(),
			(new StringField('FILE_NAME'))->configureRequired()->configureSize(255),
			(new IntegerField('FILE_SIZE'))->configureRequired(),
			(new StringField('CONTENT_TYPE'))->configureSize(255),
			(new StringField('CONTENT_ID'))->configureSize(255),
			(new BooleanField('IS_INLINE'))->configureValues('N', 'Y')->configureDefaultValue(false),
			(new IntegerField('SORT'))->configureRequired()->configureDefaultValue(100),
		];
	}
}
