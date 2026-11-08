<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\Comment;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\Type\DateTime;

final class ThreadStateTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_disk_flipchart_thread_state';
	}

	public static function getMap(): array
	{
		return [
			(new StringField('THREAD_ID'))
				->configureColumnName('thread_id')
				->configurePrimary()
				->configureSize(36)
			,
			(new IntegerField('USER_ID'))
				->configureColumnName('user_id')
				->configurePrimary()
			,
			(new BooleanField('IMPORTANT'))
				->configureColumnName('important')
				->configureValues(0, 1)
				->configureDefaultValue(false)
			,
			(new BooleanField('PINNED'))
				->configureColumnName('pinned')
				->configureValues(0, 1)
				->configureDefaultValue(false)
			,
			(new DatetimeField('PINNED_AT'))
				->configureColumnName('pinned_at')
				->configureNullable()
			,
			(new DatetimeField('CREATED_AT'))
				->configureColumnName('created_at')
				->configureDefaultValue(static fn(): DateTime => new DateTime())
			,
			(new DatetimeField('UPDATED_AT'))
				->configureColumnName('updated_at')
				->configureDefaultValue(static fn(): DateTime => new DateTime())
			,
		];
	}
}
