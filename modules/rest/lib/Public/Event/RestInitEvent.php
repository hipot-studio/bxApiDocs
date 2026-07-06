<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Event;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Rest\RestException;

final class RestInitEvent extends Event
{
	public const MODULE_ID = 'rest';
	public const TYPE = 'onRestInit';

	public function __construct(\CRestServer $server)
	{
		parent::__construct(
			self::MODULE_ID,
			self::TYPE,
			[
				'server' => $server,
			],
		);
	}

	public function getServer(): \CRestServer
	{
		return $this->getParameter('server');
	}

	public function throwIfHasErrors(): void
	{
		foreach ($this->getResults() as $eventResult)
		{
			if ($eventResult->getType() === EventResult::ERROR)
			{
				throw $this->createRestExceptionFromResult($eventResult);
			}
		}
	}

	private function createRestExceptionFromResult(EventResult $eventResult): RestException
	{
		$parameters = $eventResult->getParameters();
		$parameters = is_array($parameters) ? $parameters : [];

		$exception = new RestException(
			$parameters['message'] ?? 'Rest init event returned error result.',
			$parameters['code'] ?? RestException::ERROR_CORE,
			$parameters['status'] ?? \CRestServer::STATUS_INTERNAL,
		);

		return $exception;
	}
}
