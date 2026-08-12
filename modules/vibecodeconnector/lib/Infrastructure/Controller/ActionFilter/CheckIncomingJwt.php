<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Controller\ActionFilter;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\HttpRequest;
use Bitrix\Vibecodeconnector\Infrastructure\Controller\IncomingServerAware;
use Bitrix\Vibecodeconnector\Internal\Exception\IncomingJwtInvalidException;
use Bitrix\Vibecodeconnector\Internal\Service\Auth\IncomingJwtVerifier;
use Bitrix\Vibecodeconnector\Internal\Service\Diagnostic\IncomingJwtLog;

final class CheckIncomingJwt extends Base
{
	private const HEADER_NAME = 'Authorization';
	private const SCHEME_PREFIX = 'Bearer ';

	public function onBeforeAction(Event $event)
	{
		$action = $this->getAction();
		$request = $action->getController()->getRequest();
		$jwt = $this->extractJwt($request);

		$verifyError = null;
		if ($jwt === '')
		{
			$verifyError = new IncomingJwtInvalidException('Authorization token is missing');
		}
		else
		{
			try
			{
				$iss = ServiceLocator::getInstance()->get(IncomingJwtVerifier::class)->verify($jwt);

				$controller = $action->getController();
				if ($controller instanceof IncomingServerAware)
				{
					$controller->setIncomingServerIss($iss);
				}
			}
			catch (\Throwable $e)
			{
				$verifyError = $e;
			}
		}

		$this->logAttempt($jwt, $verifyError, $action, $request);

		if ($verifyError !== null)
		{
			$this->addError(new Error(
				$jwt === '' ? 'Authorization token is missing' : 'Authorization token is invalid',
				$jwt === '' ? 'AUTH_TOKEN_MISSING' : 'AUTH_TOKEN_INVALID',
			));

			return new EventResult(EventResult::ERROR, handler: $this);
		}

		return null;
	}

	private function logAttempt(
		string $jwt,
		?\Throwable $error,
		\Bitrix\Main\Engine\Action $action,
		HttpRequest $request,
	): void {
		try
		{
			ServiceLocator::getInstance()->get(IncomingJwtLog::class)->record(
				jwt: $jwt,
				error: $error,
				action: $action->getController()::class . '::' . $action->getName(),
				sourceIp: $this->resolveSourceIp($request),
			);
		}
		catch (\Throwable)
		{

		}
	}

	private function resolveSourceIp(HttpRequest $request): string
	{
		$server = $request->getServer();

		return (string)($server->get('REMOTE_ADDR') ?? '');
	}

	private function extractJwt(HttpRequest $request): string
	{
		$header = (string)($request->getHeader(self::HEADER_NAME) ?? '');
		if ($header === '')
		{
			$header = (string)($request->getServer()->get('REMOTE_USER') ?? '');
		}

		if ($header === '' || !str_starts_with($header, self::SCHEME_PREFIX))
		{
			return '';
		}

		return trim(substr($header, strlen(self::SCHEME_PREFIX)));
	}
}
