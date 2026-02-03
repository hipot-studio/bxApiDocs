<?php

namespace Bitrix\Im\Model;

use Bitrix\Im\V2\Common\DeleteTrait;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\MergeTrait;

class StickerUserPackTable extends DataManager
{
	use MergeTrait;
	use DeleteTrait;
	/**
	 * Returns DB table name for entity.
	 *
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_im_sticker_user_pack';
	}

	/**
	 * Returns entity map definition.
	 *
	 * @return array
	 */
	public static function getMap()
	{
		return [
			'ID' => [
				'data_type' => 'integer',
				'primary' => true,
				'autocomplete' => true,
			],
			'PACK_ID' => [
				'data_type' => 'integer',
				'required' => true,
			],
			'USER_ID' => [
				'data_type' => 'integer',
				'required' => true,
			],
			'DATE_CREATE' => [
				'data_type' => 'datetime',
				'required' => true,
			],
		];
	}
}
