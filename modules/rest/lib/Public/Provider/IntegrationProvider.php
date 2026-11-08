<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider;

use Bitrix\Rest\Internal\Repository\IntegrationRepository;
use Bitrix\Rest\Service\ServiceContainer;

class IntegrationProvider
{
	public function __construct(
		private readonly IntegrationRepository $repository = new IntegrationRepository(),
	)
	{
	}

	public function getCount()
	{
		return ServiceContainer::getInstance()->getIntegrationService()->getCount();
	}

	public function hasUserIntegrations(array $excludedAppAttributes = []): bool
	{
		return $this->repository->hasUserIntegrations($excludedAppAttributes);
	}
}
