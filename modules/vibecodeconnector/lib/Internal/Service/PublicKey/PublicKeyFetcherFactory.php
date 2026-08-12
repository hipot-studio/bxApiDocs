<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\PublicKey;

final class PublicKeyFetcherFactory
{
	public function make(PublicKeySource $source): PublicKeyFetcher
	{
		return match ($source)
		{
			PublicKeySource::STATIC => new PublicKeyStaticFetcher(),
			PublicKeySource::MICROSERVICE => new PublicKeyMicroserviceFetcher(),
		};
	}
}
