<?php

namespace Bitrix\Im\Model;

use Bitrix\Im\V2\Common\DeleteTrait;
use Bitrix\Im\V2\Common\MultiplyInsertTrait;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\MergeTrait;

class StickerTable extends DataManager
{
	use MergeTrait;
	use MultiplyInsertTrait;
	use DeleteTrait;
	/**
	 * Returns DB table name for entity.
	 *
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_im_sticker';
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
			'FILE_ID' => [
				'data_type' => 'integer',
				'required' => true,
			],
			'TYPE' => [
				'data_type' => 'string',
				'required' => true,
			],
		];
	}
}
