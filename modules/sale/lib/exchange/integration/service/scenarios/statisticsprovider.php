<?php


namespace Bitrix\Sale\Exchange\Integration\Service\Scenarios;


use Bitrix\Sale\Exchange\Integration\Service\Command\Line;

class StatisticsProvider
{
	public function register(array $params): bool
	{
		$result = Line\StatisticsProvider::getList(['xmlId'=>$params['xmlId']]);
		if (isset($result['error']))
		{
			return false;
		}

		$providerId = $result['statisticProviders'][0]['id'] ?? null;
		if ($providerId !== null)
		{
			$updateResult = Line\StatisticsProvider::update($providerId, $params);
			$providerId = $updateResult['statisticProvider']['id'] ?? null;
		}
		else
		{
			$addResult = Line\StatisticsProvider::add($params);
			$providerId = $addResult['statisticProvider']['id'] ?? null;
		}

		return (int)$providerId > 0;
	}
}
