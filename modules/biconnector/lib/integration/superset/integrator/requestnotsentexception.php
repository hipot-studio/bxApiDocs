<?php

namespace Bitrix\BIConnector\Integration\Superset\Integrator;

use Bitrix\Main\SystemException;

/**
 * Thrown when a request failed before it was handed over to the HTTP transport.
 */
final class RequestNotSentException extends SystemException
{
	public function __construct(\Throwable $previous)
	{
		parent::__construct('Proxy request was not sent: ' . $previous->getMessage(), 0, '', 0, $previous);
	}
}
