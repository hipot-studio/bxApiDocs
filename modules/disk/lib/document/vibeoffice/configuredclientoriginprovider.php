<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

/**
 * Reads the origin from the server-side setting only: the request (Host, Origin, Referer, proxies)
 * never selects it.
 */
final class ConfiguredClientOriginProvider implements ClientOriginProviderInterface
{
	public function __construct(private readonly Configuration $configuration)
	{
	}

	public function getOrigin(): ?string
	{
		return ClientOriginValidator::normalize($this->configuration->getClientOrigin());
	}
}
