<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Models;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

final class SavedSnapshotTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_disk_vo_saved_snapshot';
	}

	public static function getMap(): array
	{
		return [
			(new StringField('HOST_DOC_KEY'))->configurePrimary()->configureSize(128),
			(new IntegerField('SNAPSHOT_SEQ'))->configureSize(8)->configurePrimary(),
			(new IntegerField('RESULT_OBJECT_ID'))->configureRequired(),
			(new IntegerField('RESULT_VERSION_ID'))->configureNullable(),
			(new IntegerField('DELIVERY_ID'))->configureSize(8)->configureNullable(),
			(new StringField('SOURCE'))->configureRequired()->configureSize(16),
		];
	}
}
