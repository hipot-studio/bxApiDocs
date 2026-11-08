<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Controller;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\HttpResponse;
use Bitrix\Vibecodeconnector\Infrastructure\Service\Catalog\OpenApp\OpenAppLayoutService;
use Bitrix\Vibecodeconnector\Public\Service\AvailabilityService;

final class App extends Controller
{
	private OpenAppLayoutService $openAppLayoutService;
	private AvailabilityService $availabilityService;

	public function init(): void
	{
		parent::init();
		$serviceLocator = ServiceLocator::getInstance();
		$this->openAppLayoutService = $serviceLocator->get(OpenAppLayoutService::class);
		$this->availabilityService = $serviceLocator->get(AvailabilityService::class);
	}

	protected function getDefaultPreFilters(): array
	{
		return [
			new ActionFilter\Authentication(true),
			new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_GET]),
			new ActionFilter\Csrf(false),
		];
	}

	public function showAction(CurrentUser $currentUser, int $id): HttpResponse
	{
		$userId = (int)$currentUser->getId();
		if (!$this->availabilityService->isAvailableForUser($userId))
		{
			return $this->openAppLayoutService->renderCatalogUnavailablePageResponse();
		}

		return $this->openAppLayoutService->renderPageResponse($id, $userId);
	}
}
