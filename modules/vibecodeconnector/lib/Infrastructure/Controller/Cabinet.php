<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Controller;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\HttpResponse;
use Bitrix\Vibecodeconnector\Infrastructure\Controller\ActionFilter\CheckCatalogAvailability;
use Bitrix\Vibecodeconnector\Infrastructure\Service\Catalog\Cabinet\CabinetTransitionService;
use Bitrix\Vibecodeconnector\Public\Service\AvailabilityService;

final class Cabinet extends Controller
{
	private CabinetTransitionService $transitionService;

	public function init(): void
	{
		parent::init();
		$this->transitionService = new CabinetTransitionService();
	}

	public function configureActions(): array
	{
		$configureActions = parent::configureActions();
		$configureActions['page'] = [
			'-prefilters' => [
				ActionFilter\Csrf::class,
				ActionFilter\HttpMethod::class,
			],
			'+prefilters' => [
				new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_GET]),
				new ActionFilter\Csrf(false),
				new CheckCatalogAvailability(
					ServiceLocator::getInstance()->get(AvailabilityService::class),
				),
			],
		];

		return $configureActions;
	}

	public function pageAction(CurrentUser $currentUser): HttpResponse
	{
		return $this->transitionService->renderPageResponse((int)$currentUser->getId());
	}
}
