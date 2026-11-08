<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Controller;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Vibecodeconnector\Infrastructure\Controller\ActionFilter\CheckCatalogAvailability;
use Bitrix\Vibecodeconnector\Infrastructure\Service\Embed\EmbedOpenLayoutService;
use Bitrix\Vibecodeconnector\Public\Service\AvailabilityService;

final class Embed extends Controller
{
	protected function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			new CheckCatalogAvailability(
				ServiceLocator::getInstance()->get(AvailabilityService::class),
			),
		];
	}

	public function openLayoutAction(CurrentUser $currentUser, string $view): array
	{
		return ServiceLocator::getInstance()->get(EmbedOpenLayoutService::class)->open(
			$view,
			(int)$currentUser->getId(),
		);
	}
}
