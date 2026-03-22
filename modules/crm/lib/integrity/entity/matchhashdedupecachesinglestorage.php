<?php

namespace Bitrix\Crm\Integrity\Entity;

use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;

Loc::loadMessages(__FILE__);

class MatchHashDedupeCacheSingleStorageTable extends Main\ORM\Data\DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName()
	{
		return 'b_crm_dp_mhdc';
	}

	public static function getMap()
	{
		return [
			'DATASET_ID' => [
				'data_type' => 'string',
				'primary' => true,
				'required' => true
			],
			'DATASET_TS' => [
				'data_type' => 'datetime',
				'primary' => true,
				'required' => true
			],
			'RN' => [
				'data_type' => 'integer',
				'primary' => true,
				'required' => true
			],
			'MATCH_HASH' => [
				'data_type' => 'string',
				'required' => true
			],
			'QTY' => [
				'data_type' => 'integer',
				'required' => true
			],
		];
	}
}