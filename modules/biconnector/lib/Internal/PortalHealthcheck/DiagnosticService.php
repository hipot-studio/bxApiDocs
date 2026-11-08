<?php

namespace Bitrix\BIConnector\Internal\PortalHealthcheck;

use Bitrix\BIConnector\Integration\Superset\Integrator\RequestNotSentException;
use Bitrix\Main\Application;
use Bitrix\Main\Security\Random;

final class DiagnosticService
{
	public function __construct(
		private readonly StateRepository $repository = new StateRepository(),
		private readonly ProxyClient $proxyClient = new ProxyClient(),
	)
	{
	}

	public function start(): Snapshot
	{
		$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$token = Random::getString(64, true);
		try
		{
			$reservation = $this->repository->reserve(
				hash('sha256', $token),
				$now,
				$now->modify('+30 minutes'),
			);
		}
		catch (\DomainException $exception)
		{
			$snapshot = $this->repository->getSnapshot($now)->toArray();
			$snapshot['errorCode'] = 'HEALTHCHECK_RATE_LIMITED';
			$snapshot['nextAllowedAt'] = $exception->getMessage();

			return new Snapshot($snapshot);
		}

		try
		{
			$reply = $this->proxyClient->start($token);
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);
			$reply = new ProxyStartReply(
				0,
				errorCode: 'PROXY_NETWORK_ERROR',
				requestNotSent: $exception instanceof RequestNotSentException,
			);
		}

		return $this->repository->reconcile($reservation['id'], $reply);
	}

	public function getSnapshot(): Snapshot
	{
		return $this->repository->getSnapshot(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
	}
}
