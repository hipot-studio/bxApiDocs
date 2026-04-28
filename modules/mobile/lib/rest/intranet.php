<?php
namespace Bitrix\Mobile\Rest;

use Bitrix\Main\IO\IoException;
use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;
use Wrench\Exception\Exception;

class Intranet extends \IRestService
{
	public static function getMethods()
	{
		return [
			'mobile.intranet.departments.get' => ['callback' => [__CLASS__, 'getDepartments'], 'options' => ['private' => false]],
		];
	}

	public static function getDepartments($arParams, $offset, \CRestServer $server)
	{
		$arParams = array_change_key_case($arParams, CASE_UPPER);

		$options = [
			'FILTER' => ['SEARCH' => $arParams['FIND']],
			'LIST' => [
				'OFFSET' => intval($offset) > 0? $offset: (isset($arParams['OFFSET']) && intval($arParams['OFFSET']) > 0? intval($arParams['OFFSET']): 0),
				'LIMIT' => $arParams['LIMIT'] ?? 50,
			],
			'USER_DATA' => $arParams['USER_DATA'] == 'Y'? 'Y': 'N',
			'JSON' => 'Y',
		];

		$result = self::getStructure($options);

		return self::setNavData(
			$result['result'],
			array(
				"count" => $result['total'],
				"offset" => $options['OFFSET']
			)
		);
	}

	public static function getStructure($options = array())
	{
		$list = \Bitrix\Im\Integration\Intranet\Department::getList();

		if (isset($options['FILTER']['ID']))
		{
			foreach ($list as $key => $department)
			{
				if (!in_array($department['ID'], $options['FILTER']['ID']))
				{
					unset($list[$key]);
				}
			}
		}

		$pagination = isset($options['LIST'])? true: false;

		$limit = isset($options['LIST']['LIMIT'])? intval($options['LIST']['LIMIT']): 50;
		$offset = isset($options['LIST']['OFFSET'])? intval($options['LIST']['OFFSET']): 0;

		if (isset($options['FILTER']['SEARCH']) && mb_strlen($options['FILTER']['SEARCH']) > 1)
		{
			$count = 0;
			$breakAfterDigit = $offset === 0? $offset: false;

			$options['FILTER']['SEARCH'] = mb_strtolower($options['FILTER']['SEARCH']);
			foreach ($list as $key => $department)
			{
				$checkField = mb_strtolower($department['FULL_NAME']);
				if (
					mb_strpos($checkField, $options['FILTER']['SEARCH']) !== 0
					&& mb_strpos($checkField, ' '.$options['FILTER']['SEARCH']) === false
				)
				{
					unset($list[$key]);
				}
				if ($breakAfterDigit !== false)
				{
					$count++;
					if ($count === $breakAfterDigit)
					{
						break;
					}
				}
			}
		}

		$count = count($list);

		$list = array_slice($list, $offset, $limit);

		if ($options['JSON'] == 'Y' || $options['USER_DATA'] == 'Y')
		{
			if ($options['JSON'] == 'Y')
			{
				$list = array_values($list);
			}
			foreach ($list as $key => $department)
			{
				if ($options['USER_DATA'] == 'Y')
				{
					$userData = \Bitrix\Im\User::getInstance($department['MANAGER_USER_ID']);
					$department['MANAGER_USER_DATA'] = $options['JSON'] == 'Y'? $userData->getArray(Array('JSON' => 'Y')): $userData;
				}

				$list[$key] = $options['JSON'] == 'Y'? array_change_key_case($department, CASE_LOWER): $department;
			}
		}

		if ($options['JSON'] == 'Y')
		{
			$list = $pagination? ['total' => $count, 'result' => $list] : $list;
		}
		else
		{
			$list = $pagination? ['TOTAL' => $count, 'RESULT' => $list] : $list;
		}

		return $list;
	}
}