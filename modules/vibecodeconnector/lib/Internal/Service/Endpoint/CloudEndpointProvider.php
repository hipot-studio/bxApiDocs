<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Endpoint;

use Bitrix\Main\Config\Option;

final class CloudEndpointProvider
{
	public function __construct(
		private readonly BaseEndpointProvider $baseEndpointProvider = new BaseEndpointProvider(),
	) {
	}

	public function getCloudUrl(): string
	{
		$value = (string)Option::get('vibecodeconnector', 'cloud_shared_endpoint_url', '');

		return $value !== '' ? $value : $this->baseEndpointProvider->getDefaultUrl();
	}

	public function setCloudUrl(string $url): void
	{
		Option::set('vibecodeconnector', 'cloud_shared_endpoint_url', $url);
	}

	public function clearCloudUrl(): void
	{
		Option::delete('vibecodeconnector', ['name' => 'cloud_shared_endpoint_url']);
	}
}
