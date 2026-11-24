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

final class PlatformSessionTable extends DataManager
{
	public const STATUS_PENDING = 'pending';
	public const STATUS_REFUSED = 'refused';
	public const STATUS_ACTIVE = 'active';
	public const STATUS_TERMINATED = 'terminated';

	public static function getTableName(): string
	{
		return 'b_disk_vo_session';
	}

	public static function getMap(): array
	{
		$fields = [
			(new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new StringField('HOST_DOC_KEY'))->configureRequired()->configureSize(128),
			(new StringField('API_SESSION_ID'))->configureNullable()->configureSize(36),
			(new IntegerField('DOCUMENT_SESSION_ID'))->configureNullable(),
			(new StringField('OPEN_TOKEN'))->configureNullable()->configureSize(36),
			(new StringField('EDITOR_KEY'))->configureNullable()->configureSize(255),
			(new IntegerField('REV'))->configureNullable(),
			(new StringField('STATUS'))->configureRequired()->configureSize(16),
			(new DatetimeField('CREATE_TIME'))->configureDefaultValue(static fn() => new DateTime()),
			(new DatetimeField('UPDATE_TIME'))->configureDefaultValue(static fn() => new DateTime()),
		];
		$sharedFields = [
			'PLATFORM_USER_ID' => (new StringField('PLATFORM_USER_ID'))->configureSize(64)->configureNullable(),
			'ACTOR_USER_ID' => (new IntegerField('ACTOR_USER_ID'))->configureNullable(),
			'ACCESS_SOURCE_TYPE' => (new StringField('ACCESS_SOURCE_TYPE'))->configureSize(16)->configureNullable(),
			'ACCESS_SOURCE_ID' => (new IntegerField('ACCESS_SOURCE_ID'))->configureNullable(),
			'ACCESS_CONTEXT_HASH' => (new StringField('ACCESS_CONTEXT_HASH'))->configureSize(64)->configureNullable(),
			'ACCESS_CONTEXT' => (new TextField('ACCESS_CONTEXT'))->configureNullable(),
			'ACCESS_EXPIRES_AT' => (new DatetimeField('ACCESS_EXPIRES_AT'))->configureNullable(),
			'ACCESS_REVOKE_STATE' => (new IntegerField('ACCESS_REVOKE_STATE'))->configureDefaultValue(0),
			'REVOKE_RETRY_AT' => (new DatetimeField('REVOKE_RETRY_AT'))->configureNullable(),
			'REVOKE_REQUESTED_AT' => (new DatetimeField('REVOKE_REQUESTED_AT'))->configureNullable(),
		];
		foreach (array_intersect(SharedEditingSchema::sessionFields(), array_keys($sharedFields)) as $name)
		{
			$fields[] = $sharedFields[$name];
		}

		return $fields;
	}
}
