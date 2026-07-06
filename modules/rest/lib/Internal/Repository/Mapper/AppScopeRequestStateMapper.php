<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Repository\Mapper;

use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\Internal\Entity\Application\AppScopeRequest;
use Bitrix\Rest\Internal\Entity\Application\AppScopeRequestStatus;
use Bitrix\Rest\Internal\Entity\Application\AppScopeRequestState;

class AppScopeRequestStateMapper
{
	public function stateFromRawData(array $requestStateData): AppScopeRequestState
	{
		$state = new AppScopeRequestState(
			requestId: (int)$requestStateData['REQUEST_ID'],
			status: AppScopeRequestStatus::from($requestStateData['STATUS']),
			comment: $requestStateData['COMMENT'] ?? '',
		);

		$state->setId((int)$requestStateData['ID']);

		if ($requestStateData['DATE_CREATE'] instanceof DateTime)
		{
			$state->setDateCreate($requestStateData['DATE_CREATE']);
		}

		return $state;
	}
}
