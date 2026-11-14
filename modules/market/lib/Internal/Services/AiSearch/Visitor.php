<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Services\AiSearch;

use Bitrix\Main\Application;
use Bitrix\Main\Context;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Session\SessionInterface;
use Bitrix\Main\Web\Cookie;

final class Visitor
{
	public const COOKIE_NAME = 'MARKET_AI_VISITOR_ID';
	public const SESSION_KEY = 'MARKET_AI_SEARCH_SESSION_ID';

	private const ID_PATTERN = '/^[0-9a-f]{32}$/D';
	private const COOKIE_LIFETIME = 365 * 24 * 60 * 60;

	private ?string $visitorId = null;
	private ?string $searchSessionId = null;

	public function __construct(
		private readonly HttpRequest $request,
		private readonly HttpResponse $response,
		private readonly SessionInterface $session,
	)
	{
	}

	public static function createCurrent(): self
	{
		$context = Context::getCurrent();

		return new self(
			$context->getRequest(),
			$context->getResponse(),
			Application::getInstance()->getSession(),
		);
	}

	public function getVisitorId(): string
	{
		if ($this->visitorId === null)
		{
			$this->visitorId = $this->resolveVisitorId();
		}

		return $this->visitorId;
	}

	public function getSearchSessionId(): string
	{
		if ($this->searchSessionId === null)
		{
			$this->searchSessionId = $this->resolveSearchSessionId();
		}

		return $this->searchSessionId;
	}

	private function resolveVisitorId(): string
	{
		$cookieValue = $this->request->getCookie(self::COOKIE_NAME);

		if (self::isValidId($cookieValue))
		{
			return $cookieValue;
		}

		$id = self::generateId();

		$cookie = new Cookie(self::COOKIE_NAME, $id, time() + self::COOKIE_LIFETIME);
		$cookie->setHttpOnly(true);
		$cookie->setPath('/');
		$cookie->setSpread(Cookie::SPREAD_DOMAIN);

		$this->response->addCookie($cookie);

		return $id;
	}

	private function resolveSearchSessionId(): string
	{
		$value = $this->session->get(self::SESSION_KEY);

		if (self::isValidId($value))
		{
			return $value;
		}

		$id = self::generateId();

		$this->session->set(self::SESSION_KEY, $id);

		return $id;
	}

	private static function isValidId(mixed $value): bool
	{
		return is_string($value) && preg_match(self::ID_PATTERN, $value) === 1;
	}

	private static function generateId(): string
	{
		return bin2hex(random_bytes(16));
	}
}
