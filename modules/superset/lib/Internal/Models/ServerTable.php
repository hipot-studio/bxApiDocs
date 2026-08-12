<?php

namespace Bitrix\Superset\Internal\Models;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Event;
use Bitrix\Main\ORM\EventResult;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\EnumField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\SecretField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\Type\DateTime;

class ServerTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_superset_server';
	}

	public static function getObjectClass(): string
	{
		return \Bitrix\Superset\Internal\Models\EO_Server::class;
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new StringField('HOST')),
			(new SecretField('ACCESS_PASSWORD')),
			(new StringField('INSTANCE_KEY'))
				->configureNullable()
			,
			(new StringField('INSTANCE_USERNAME'))
				->configureNullable()
			,
			(new SecretField('TOKEN')),
			(new SecretField('REFRESH_TOKEN')),
			(new StringField('START_JOB_ID')),
			(new IntegerField('ACCOUNT_ID'))
				->configureRequired()
			,
			(new IntegerField('VERSION')),
			(new EnumField(('IS_PORTAL_ID_VERIFIED')))
				->configureValues(['Y', 'N'])
				->configureDefaultValue('N'),
			(new StringField('PORTAL_ID'))
				->configureNullable()
				->configureSize(50)
			,
			(new StringField('PORTAL_URL'))
				->configureNullable()
				->configureSize(255)
			,
			(new SecretField('JWT_SECRET')),
			(new DatetimeField('DATE_START_ATTEMPT'))
				->configureNullable()
			,
			(new DatetimeField('DATE_CREATE'))
				->configureDefaultValueNow()
			,
			(new DatetimeField('DATE_UPDATE'))
				->configureDefaultValueNow()
			,
		];
	}

	public static function onBeforeUpdate(Event $event): EventResult
	{
		$result = new EventResult();

		$fields = $event->getParameter('fields');

		if (!isset($fields['DATE_UPDATE']))
		{
			$result->modifyFields([
				'DATE_UPDATE' => new DateTime(),
			]);
		}

		return $result;
	}
}
