<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Provider;

use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;

/**
 * Current state of the reports instance.
 * Left open for extension so unit tests can replace it with a stub.
 */
class BiInstanceStatusProvider
{
	/**
	 * @return string One of {@see SupersetInitializer}::SUPERSET_STATUS_*.
	 */
	public function getStatus(): string
	{
		return SupersetInitializer::getSupersetStatus();
	}
}
