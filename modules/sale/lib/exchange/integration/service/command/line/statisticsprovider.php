<?php


namespace Bitrix\Sale\Exchange\Integration\Service\Command\Line;


use Bitrix\Sale\Exchange\Integration;

class StatisticsProvider
	implements Integration\Service\Command\IProxy
{
	public static function getProxy()
	{
		return new Integration\Rest\RemoteProxies\Sale\StatisticsProvider();
	}

	/**
	 * @param array $fields
	 * @return mixed
	 * @throws Integration\Exception\CommandLineException
	 */
	public static function add(array $fields)
	{
		$proxy = static::getProxy();
		$r = $proxy->add($fields);
		if($r->isSuccess())
		{
			$result = $r->getData()['DATA']['result'];
		}
		else
		{
			throw new Integration\Exception\CommandLineException(implode(',', $r->getErrorMessages()));
		}

		return $result;
	}

	/**
	 * @param $id
	 * @param array $fields
	 * @return mixed
	 * @throws Integration\Exception\CommandLineException
	 */
	public static function update($id, array $fields)
	{
		$proxy = static::getProxy();
		$r = $proxy->update($id, $fields);
		if($r->isSuccess())
		{
			$result = $r->getData()['DATA']['result'];
		}
		else
		{
			throw new Integration\Exception\CommandLineException(implode(',', $r->getErrorMessages()));
		}

		return $result;
	}

	public static function getList($filter)
	{
		$proxy = static::getProxy();
		$r = $proxy->getList([], $filter);
		if($r->isSuccess())
		{
			$result = $r->getData()['DATA']['result'];
		}
		else
		{
			$result['error'] = $r->getErrorMessages();
		}

		return $result;
	}

//	static public function register(array $params)
//	{
//		$result = static::getList(['xmlId'=>$params['fields']['xmlId']]);
//
//		if($result['statisticProviders'][0])
//		{
//			$providerId = $result['statisticProviders'][0]['id'];
//		}
//		else
//		{
//			$providerId = static::add($params['fields'])['statisticProvider']['id'];
//		}
//
//		return $providerId > 0;
//	}

}
