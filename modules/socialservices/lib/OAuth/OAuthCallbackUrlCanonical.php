<?php

namespace Bitrix\Socialservices\OAuth;

use Bitrix\Main\Application;
use Bitrix\Main\Web\Uri;

/**
 * Canonical form of an OAuth callback URL for self-redirect loop detection.
 */
final class OAuthCallbackUrlCanonical
{
	private function __construct(private Uri $uri)
	{}

	public static function createFromUri(string $uri): ?self
	{
		$rawUri = trim($uri);
		if ($rawUri === '' || str_starts_with($rawUri, '#'))
		{
			return null;
		}

		$uriObject = new Uri($rawUri);
		$uriObject->toAbsolute();

		return new self($uriObject);
	}

	public static function createFromCurrentUri(): ?self
	{
		$requestUri = (string)Application::getInstance()->getContext()->getRequest()->getRequestUri();
		if ($requestUri === '')
		{
			$requestUri = '/';
		}

		return self::createFromUri($requestUri);
	}

	public function equals(self $other): bool
	{
		return $this->uri->getLocator() === $other->uri->getLocator();
	}
}
