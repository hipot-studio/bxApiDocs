<?php

namespace Bitrix\Crm\Activity\Entity;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;

final class ActMailBodyBindTable extends DataManager
{
	public static function getTableName()
	{
		return 'b_crm_act_mail_body_bind';
	}

	public static function getMap()
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('BODY_ID'))
				->configureRequired()
			,
			(new IntegerField('OWNER_TYPE_ID'))
				->configureRequired()
			,
			(new IntegerField('OWNER_ID'))
				->configureRequired()
			,
		];
	}
}
