<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\PublicKey;

use Bitrix\Vibecodeconnector\Internal\Exception\PublicKeyFetchFailedException;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\EndpointResolver;

interface PublicKeyFetcher
{
	/**
	 * @throws PublicKeyFetchFailedException
	 */
	public function fetch(EndpointResolver $endpoints, string $iss): string;
}
