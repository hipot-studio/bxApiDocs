<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Integration;

use Bitrix\Booking\Internals\Container;

class IntegrationManager
{
	public function getIntegrationsData(): array
	{
		$data = [];
		foreach ($this->getAvailableIntegrations() as $integration)
		{
			$data[] = [
				'code' => $integration->getName(),
				'status' => $integration->getStatus(),
			];
		}

		return $data;
	}

	private function getAvailableIntegrations(): array
	{
		$integrations = $this->getAllIntegrations();

		return array_filter(
			$integrations,
			static fn(IntegrationServiceInterface $integration) => $integration->isAvailable(),
		);
	}

	private function getAllIntegrations(): array
	{
		return [
			Container::getYandexIntegrationService(),
		];
	}
}
