<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Repository\Mapper;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\Internal\Entity\Application\AppScopeRequest;

class AppScopeRequestMapper
{
	private AppScopeRequestStateMapper $stateMapper;

	public function __construct()
	{
		$this->stateMapper = ServiceLocator::getInstance()->get(AppScopeRequestStateMapper::class);
	}

	/**
	 * @param array $requestData AppScopeRequestTable raw data
	 * @param array|null $requestStateData AppScopeRequestStateTable raw data
	 */
	public function requestFromRawData(array $requestData, ?array $requestStateData = null): AppScopeRequest
	{
		$scope = new AppScopeRequest(
			appId: (int)$requestData['APP_ID'],
			scopes: is_array($requestData['SCOPE']) ? $requestData['SCOPE'] : [],
			stateId: $requestData['STATE_ID'] !== null ? (int)$requestData['STATE_ID'] : null,
		);

		$scope->setId((int)$requestData['ID']);

		if ($requestData['DATE_CREATE'] instanceof DateTime)
		{
			$scope->setDateCreate($requestData['DATE_CREATE']);
		}

		if ($requestStateData !== null)
		{
			$scope->setCurrentState($this->stateMapper->stateFromRawData($requestStateData));
		}

		return $scope;
	}
}
