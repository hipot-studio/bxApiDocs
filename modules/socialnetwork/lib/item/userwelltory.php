<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage socialnetwork
 * @copyright 2001-2012 Bitrix
 */
namespace Bitrix\Socialnetwork\Item;

use Bitrix\Socialnetwork\UserWelltoryTable;

class UserWelltory
{
	public static function getAccess(array $fields = [])
	{
		$userId = (
			isset($fields['userId'])
				? intval($fields['userId'])
				: 0
		);

		if ($userId <= 0)
		{
			return false;
		}

		$value = \CUserOptions::getOption(
			'socialnetwork',
			self::getAccessOptionName(),
			'N',
			$userId
		);

		return ($value == 'Y' ? 'Y' : 'N');
	}

	public static function setAccess(array $fields = [])
	{

		$userId = (
			isset($fields['userId'])
				? intval($fields['userId'])
				: 0
		);

		$value = (
			isset($fields['value'])
			&& $fields['value'] == 'Y'
				? 'Y'
				: 'N'
		);

		return (\CUserOptions::setOption(
				'socialnetwork',
				self::getAccessOptionName(),
				$value,
				false,
				$userId
			)
				? $value
				: false
		);
	}

	public static function getHistoricData(array $fields = [])
	{
		$result = [];

		$userId = (
			isset($fields['userId'])
				? intval($fields['userId'])
				: 0
		);

		$limit = (
			isset($fields['limit'])
				? intval($fields['limit'])
				: 1
		);

		$res = UserWelltoryTable::getList([
			'filter' => [
				'=USER_ID' => $userId
			],
			'order' => [
				'DATE_MEASURE' => 'desc'
			],
			'select' => [ 'ID', 'DATE_MEASURE', 'STRESS', 'STRESS_TYPE', 'STRESS_COMMENT', 'HASH' ],
			'limit' => $limit
		]);
		while ($dataFields = $res->fetch())
		{
			$result[] = [
				'id' => $dataFields['ID'],
				'date' => $dataFields['DATE_MEASURE'],
				'value' => intval($dataFields['STRESS']),
				'type' => ($dataFields['STRESS_TYPE'] <> '' ? $dataFields['STRESS_TYPE'] : ''),
				'typeDescription' => '',
				'comment' => ($dataFields['STRESS_COMMENT'] <> '' ? $dataFields['STRESS_COMMENT'] : ''),
				'hash' => ($dataFields['HASH'] <> '' ? $dataFields['HASH'] : ''),
			];
		}

		return $result;
	}

	private static function getAccessOptionName()
	{
		return "welltory_access";
	}
}
