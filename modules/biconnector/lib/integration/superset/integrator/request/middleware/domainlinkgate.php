<?php

namespace Bitrix\BIConnector\Integration\Superset\Integrator\Request\Middleware;

use Bitrix\BIConnector\Integration\Superset\Integrator\Logger\IntegratorLogger;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorRequest;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Superset\DomainLinkService;
use Bitrix\Main\Error;

class DomainLinkGate extends Base
{
	public function __construct(private readonly IntegratorLogger $logger)
	{}

	public static function getMiddlewareId(): string
	{
		return 'DOMAIN_LINK_GATE';
	}

	public function beforeRequest(IntegratorRequest $request): ?IntegratorResponse
	{
		if (!DomainLinkService::getInstance()->isLinked())
		{
			$this->skipAfterMiddlewares();

			$errors = [new Error("For action '{$request->getAction()}' required linked superset")];
			$this->logger->logErrors($errors);

			return new IntegratorResponse(
				IntegratorResponse::STATUS_INNER_ERROR,
				null,
				$errors
			);
		}

		return null;
	}

	public function afterRequest(IntegratorRequest $request, IntegratorResponse $response): IntegratorResponse
	{
		if ($response->hasErrors())
		{
			foreach ($response->getErrors() as $error)
			{
				if ($error->getCode() === 'missmatch_server_name')
				{
					DomainLinkService::getInstance()->setUnlinked();
					break;
				}
			}
		}

		return parent::afterRequest($request, $response);
	}
}

