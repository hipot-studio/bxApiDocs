<?php

namespace Bitrix\Crm\Integrity;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

abstract class MatchHashDedupeCacheTable extends DataManager
{
	/**
	 * @inheritdoc
	 */
	public static function getMap()
	{
		return [
			new StringField('MATCH_HASH', ['size' => 32, 'primary' => true]),
			new IntegerField('QTY'),
		];
	}
}
