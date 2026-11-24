<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Models;

use Bitrix\Disk\Document\Vibeoffice\SharedEditingSchema;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\Type\DateTime;

final class DocumentStateTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_disk_vo_document';
	}

	public static function getMap(): array
	{
		$fields = [
			(new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new StringField('HOST_DOC_KEY'))->configureRequired()->configureSize(128),
			(new StringField('NAMESPACE'))->configureSize(64)->configureDefaultValue(''),
			(new StringField('KEY_MODE'))->configureRequired()->configureSize(8)->configureDefaultValue('legacy'),
			(new IntegerField('OBJECT_ID'))->configureRequired(),
			(new IntegerField('VERSION_ID'))->configureDefaultValue(0),
			(new IntegerField('SESSION_TYPE'))->configureRequired(),
			(new IntegerField('EXTERNAL_LINK_ID'))->configureDefaultValue(0),
			(new StringField('CONTENT_GENERATION'))->configureRequired()->configureSize(64),
			(new IntegerField('EXPECTED_FILE_ID'))->configureRequired(),
			(new IntegerField('EXPECTED_CONTENT_VERSION'))->configureRequired(),
			(new StringField('EXPECTED_ETAG'))->configureSize(255)->configureDefaultValue(''),
			(new IntegerField('LAST_SNAPSHOT_SEQ'))->configureSize(8)->configureDefaultValue(0),
			(new IntegerField('CONFLICT_OBJECT_ID'))->configureNullable(),
			(new IntegerField('CONFLICTED'))->configureDefaultValue(0),
			(new DatetimeField('UPDATED_TIME'))->configureDefaultValue(static fn() => new DateTime()),
		];
		$sharedFields = [
			'EDIT_SCOPE_VERSION' => (new IntegerField('EDIT_SCOPE_VERSION'))->configureDefaultValue(0),
			'EDIT_FENCE_STATE' => (new IntegerField('EDIT_FENCE_STATE'))->configureDefaultValue(0),
			'TERMINAL_PROOF' => (new TextField('TERMINAL_PROOF'))->configureNullable(),
		];
		foreach (array_intersect(SharedEditingSchema::documentFields(), array_keys($sharedFields)) as $name)
		{
			$fields[] = $sharedFields[$name];
		}

		return $fields;
	}
}
