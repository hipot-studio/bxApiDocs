<?php

namespace Bitrix\Superset\Internal\Models;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Event;
use Bitrix\Main\ORM\EventResult;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations;
use Bitrix\Main\ORM\Fields\SecretField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Type\DateTime;

class UserTable extends DataManager
{
	public const ERROR_DUPLICATE_ACCOUNT = 3;

	public static function getTableName(): string
	{
		return 'b_superset_user';
	}

	public static function getObjectClass(): string
	{
		return \Bitrix\Superset\Internal\Models\EO_User::class;
	}

	public static function getCollectionClass(): string
	{
		return \Bitrix\Superset\Internal\Models\EO_User_Collection::class;
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new StringField('LOGIN'))->addValidator(new LengthValidator(1, 64)),
			(new SecretField('ACCESS_PASSWORD')),
			(new IntegerField('SERVER_ID')),
			new Relations\Reference(
				'SERVER',
				ServerTable::class,
				Join::on('this.SERVER_ID', 'ref.ID')
			),
			(new DatetimeField('CREATED'))
				->configureDefaultValue(function() {
					return new DateTime();
				})
			,
			new DatetimeField('UPDATED'),
			new IntegerField('EXTERNAL_ID'),
			(new StringField('CLIENT_ID'))->addValidator(new LengthValidator(1, 32)),
		];
	}

	public static function onBeforeUpdate(Event $event): EventResult
	{
		$result = new EventResult();
		$result->modifyFields([
			'UPDATED' => new DateTime(),
		]);

		return $result;
	}
}
