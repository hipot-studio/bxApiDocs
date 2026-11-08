<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Vibecode;

use Bitrix\Main\Web\Uri;
use Bitrix\Vibecodeconnector\Internal\Entity\Vibecode\VibecodeBrand;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\VibecodeUrlProvider;

/** The brand follows the Vibecode endpoint selected for the current portal. */
class VibecodeBrandResolver
{
	private const BITRIX24_HOST_SUFFIXES = ['.ru', '.tech'];

	public function __construct(
		private readonly VibecodeUrlProvider $urlProvider = new VibecodeUrlProvider(),
	) {
	}

	public function resolve(): VibecodeBrand
	{
		// Uri lowercases the host on its own and leaves it empty for anything it cannot parse
		$host = rtrim((new Uri($this->endpointUrl()))->getHost(), '.');

		// An endpoint without a host falls back to Bitrix24.
		if ($host === '')
		{
			return VibecodeBrand::Bitrix24;
		}

		foreach (self::BITRIX24_HOST_SUFFIXES as $suffix)
		{
			if (str_ends_with($host, $suffix))
			{
				return VibecodeBrand::Bitrix24;
			}
		}

		return VibecodeBrand::Alaio;
	}

	protected function endpointUrl(): string
	{
		return $this->urlProvider->getEndpointUrl();
	}
}
