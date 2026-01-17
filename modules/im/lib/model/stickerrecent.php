<?php
namespace Bitrix\Im\Model;

use Bitrix\Im\V2\Common\DeleteTrait;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\MergeTrait;

class StickerRecentTable extends DataManager
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
		return 'b_im_sticker_recent';
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
			'USER_ID' => [
				'data_type' => 'integer',
				'required' => true,
			],
			'STICKER_ID' => [
				'data_type' => 'integer',
				'required' => true,
			],
			'PACK_ID' => [
				'data_type' => 'integer',
				'required' => true,
			],
			'PACK_TYPE' => [
				'data_type' => 'string',
				'required' => true,
			],
			'DATE_CREATE' => [
				'data_type' => 'datetime',
				'required' => true,
				'default_value' => array(__CLASS__, 'getCurrentDate'),
			],
		];
	}

	public static function getCurrentDate()
	{
		return new \Bitrix\Main\Type\DateTime();
	}
}
