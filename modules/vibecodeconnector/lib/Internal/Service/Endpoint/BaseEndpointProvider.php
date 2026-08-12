<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Endpoint;

use Bitrix\Main\Config\Option;

final class BaseEndpointProvider
{
	private ?string $defaultUrl = null;

	public function getBaseUrl(): string
	{
		$value = (string)Option::get('vibecodeconnector', 'endpoint_base_url', '');
		if ($value !== '')
		{
			return $value;
		}

		return $this->getDefaultUrl();
	}

	public function getDefaultUrl(): string
	{
		return $this->defaultUrl ??= (new DefaultEndpointResolver())->resolve();
	}

	/**
	 * @return string[]
	 */
	public function getAvailableUrls(): array
	{
		return (new DefaultEndpointResolver())->getAvailableUrls();
	}

	public function setBaseUrl(string $url): void
	{
		Option::set('vibecodeconnector', 'endpoint_base_url', $url);
	}
}
