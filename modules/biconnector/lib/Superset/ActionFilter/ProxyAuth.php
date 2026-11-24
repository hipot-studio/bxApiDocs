<?php

namespace Bitrix\BIConnector\Superset\ActionFilter;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

final class ProxyAuth extends Base
{
	public const SUPERSET_PROXY_TOKEN_OPTION = '~superset_proxy_token';

	/**
	 * @param bool $acceptWithoutStoredToken A portal that keeps no token of the service (a new database after
	 * a reinstall) lets the request through unchecked. Only for read-only actions the service calls on
	 * the registered address itself; a stored token is always checked.
	 */
	public function __construct(private readonly bool $acceptWithoutStoredToken = false)
	{
		parent::__construct();
	}

	public function onBeforeAction(Event $event): ?EventResult
	{
		$context = Context::getCurrent();

		$clientToken = $context->getRequest()->getJsonList()->get('token');
		$storedClientToken = Option::get('biconnector', self::SUPERSET_PROXY_TOKEN_OPTION, '');

		// Only a non-empty token proves the service: no stored token never matches a request.
		if (!is_string($storedClientToken) || $storedClientToken === '')
		{
			return $this->acceptWithoutStoredToken ? null : $this->refuse();
		}

		if (!is_string($clientToken) || !hash_equals($storedClientToken, $clientToken))
		{
			return $this->refuse();
		}

		return null;
	}

	private function refuse(): EventResult
	{
		$this->addError(new Error('Proxy token is invalid', 'PROXY_TOKEN_IS_INVALID'));

		return new EventResult(EventResult::ERROR, null, null, $this);
	}
}