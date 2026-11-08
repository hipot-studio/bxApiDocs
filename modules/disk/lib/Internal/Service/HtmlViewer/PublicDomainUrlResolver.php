<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\HtmlViewer;

use Bitrix\Main\Config\Option;

final class PublicDomainUrlResolver
{
	private const PUBLIC_ORIGIN = 'https://bitrix24public.com';

	private readonly ?string $canonicalHost;

	public function __construct(?string $canonicalHost)
	{
		$this->canonicalHost = self::normalizeHost($canonicalHost);
	}

	public static function createFromPortalConfiguration(): self
	{
		$canonicalHost = defined('SITE_SERVER_NAME') && SITE_SERVER_NAME
			? (string)SITE_SERVER_NAME
			: Option::get('main', 'server_name', '')
		;

		return new self($canonicalHost);
	}

	public function getCanonicalUrl(string $relativeUrl): ?string
	{
		if ($this->canonicalHost === null || !self::isValidRelativeUrl($relativeUrl))
		{
			return null;
		}

		return "https://{$this->canonicalHost}{$relativeUrl}";
	}

	public function getPublicUrl(string $relativeUrl): ?string
	{
		if ($this->canonicalHost === null || !self::isValidRelativeUrl($relativeUrl))
		{
			return null;
		}

		return self::PUBLIC_ORIGIN . "/{$this->canonicalHost}{$relativeUrl}";
	}

	private static function normalizeHost(?string $host): ?string
	{
		if ($host === null || $host === '' || strlen($host) > 253)
		{
			return null;
		}

		$host = mb_strtolower($host);
		if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false)
		{
			return null;
		}

		return $host;
	}

	private static function isValidRelativeUrl(string $relativeUrl): bool
	{
		return str_starts_with($relativeUrl, '/')
			&& !str_starts_with($relativeUrl, '//')
			&& !str_contains($relativeUrl, "\r")
			&& !str_contains($relativeUrl, "\n")
		;
	}
}
