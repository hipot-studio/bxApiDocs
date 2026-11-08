<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Endpoint;

use Bitrix\Main\Config\Feature;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Vibecodeconnector\Internal\Config\Feature\CabinetSeamlessEntry;
use Bitrix\Vibecodeconnector\Internal\Integration\Socialservices\NetworkService;

final class VibecodeUrlProvider
{
	public function __construct(
		private readonly ?NetworkService $networkService = null,
		private readonly ?BaseEndpointProvider $baseEndpointProvider = null,
		private readonly ?CloudEndpointProvider $cloudEndpointProvider = null,
	) {
	}

	public function getEndpointUrl(): string
	{
		$serviceLocator = ServiceLocator::getInstance();
		$networkService = $this->networkService ?? $serviceLocator->get(NetworkService::class);

		return $networkService->isCloudPortal()
			? ($this->cloudEndpointProvider ?? $serviceLocator->get(CloudEndpointProvider::class))->getCloudUrl()
			: ($this->baseEndpointProvider ?? $serviceLocator->get(BaseEndpointProvider::class))->getBaseUrl()
		;
	}

	public function getDashboardUrl(?int $userId = null): string
	{
		if (Feature::isEnabled(CabinetSeamlessEntry::class))
		{
			$userId ??= (int)CurrentUser::get()->getId();
			if ($userId > 0)
			{
				return UrlManager::getInstance()->create('vibecodeconnector.Cabinet.page')->getUri();
			}
		}

		return rtrim($this->getEndpointUrl(), '/') . '/dashboard';
	}
}
