<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Infrastructure\Controllers\Filter;

use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Web\JWT;

final class Authorization extends ActionFilter\Base
{
	private const HEADER_AUTHORIZATION = 'authorization';

	private ?string $secretKey;

	public function __construct(?string $secretKey)
	{
		parent::__construct();

		$this->secretKey = $secretKey;
	}

	public function onBeforeAction(Event $event): ?EventResult
	{
		if ($this->secretKey === null || $this->secretKey === '')
		{
			Context::getCurrent()->getResponse()->setStatus('401 Unauthorized');
			$this->addError(new Error('Proxy not configured'));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		$token = $this->getBearerToken();
		if ($token === null || $token === '')
		{
			Context::getCurrent()->getResponse()->setStatus('401 Unauthorized');
			$this->addError(new Error('Missing or invalid Authorization header', 401));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		try
		{
			JWT::decode($token, $this->secretKey, ['HS256']);
		}
		catch (\Exception)
		{
			Context::getCurrent()->getResponse()->setStatus('403 Forbidden');
			$this->addError(new Error('Invalid signature', 403));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}

	private function getBearerToken(): ?string
	{
		$request = $this->getAction()?->getController()?->getRequest();
		if ($request === null)
		{
			return null;
		}

		$header = $request->getHeader(self::HEADER_AUTHORIZATION);
		if (is_array($header))
		{
			$header = $header[0] ?? null;
		}
		if (!is_string($header) || stripos($header, 'Bearer ') !== 0)
		{
			return null;
		}

		$token = trim(substr($header, 7));

		return $token !== '' ? $token : null;
	}
}
