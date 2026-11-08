<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Clients;

use Bitrix\Main\Config\Option;
use Bitrix\Main\IO\Path;
use Bitrix\Main\Web\Uri;
use Psr\Http\Message\UriInterface;

final class AvatarSourceResolver
{
	private const MAX_URL_LENGTH = 2048;

	public function __construct(
		private readonly ?string $portalOrigin = null,
		private readonly ?string $uploadDirectory = null,
	)
	{
	}

	public static function createForCurrentPortal(): self
	{
		$serverName = defined('SITE_SERVER_NAME')
			? trim((string)constant('SITE_SERVER_NAME'))
			: ''
		;
		if ($serverName === '')
		{
			$serverName = trim(Option::get('main', 'server_name', ''));
		}

		return new self($serverName === '' ? null : 'https://' . $serverName);
	}

	public function resolve(string $avatarUrl): ?string
	{
		try
		{
			$uri = new Uri($avatarUrl);
			if ($this->isSafeHttpsUrl($uri))
			{
				return (string)$uri;
			}

			$localUploadPath = $this->resolveLocalUploadPath($uri);
			if ($localUploadPath === null || $uri->getUserInfo() !== '')
			{
				return null;
			}

			$uri = $uri->withPath($this->encodePath($localUploadPath));

			$portalOrigin = $this->getPortalOrigin();
			if ($portalOrigin === null)
			{
				return null;
			}

			if ($uri->getScheme() === '' && $uri->getAuthority() === '')
			{
				$uri->resolveRelativeUri($portalOrigin);
			}
			elseif (
				strtolower($uri->getScheme()) !== 'http'
				|| $uri->getHost() === ''
				|| strcasecmp($uri->getHost(), $portalOrigin->getHost()) !== 0
			)
			{
				return null;
			}

			$securePort = strtolower($portalOrigin->getScheme()) === 'https'
				? $portalOrigin->getPort()
				: null
			;
			$secureUri = $uri
				->withScheme('https')
				->withPort($securePort)
			;

			return $this->isSafeHttpsUrl($secureUri) ? (string)$secureUri : null;
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	private function getPortalOrigin(): ?Uri
	{
		if ($this->portalOrigin === null)
		{
			return null;
		}

		$origin = new Uri($this->portalOrigin);
		if (
			!in_array(strtolower($origin->getScheme()), ['http', 'https'], true)
			|| $origin->getHost() === ''
			|| $origin->getUserInfo() !== ''
			|| !in_array($origin->getPath(), ['', '/'], true)
			|| $origin->getQuery() !== ''
			|| $origin->getFragment() !== ''
		)
		{
			return null;
		}

		return $origin;
	}

	private function isSafeHttpsUrl(UriInterface $uri): bool
	{
		return strlen((string)$uri) <= self::MAX_URL_LENGTH
			&& strtolower($uri->getScheme()) === 'https'
			&& $uri->getHost() !== ''
			&& $uri->getUserInfo() === ''
		;
	}

	private function resolveLocalUploadPath(Uri $uri): ?string
	{
		$decodedPath = rawurldecode($uri->getPath());
		if ((new Uri($decodedPath))->isPathTraversal())
		{
			return null;
		}

		$normalizedPath = Path::normalize($decodedPath);
		if (!is_string($normalizedPath))
		{
			return null;
		}

		$uploadDirectory = trim(
			$this->uploadDirectory ?? Option::get('main', 'upload_dir', 'upload'),
			'/\\',
		);
		if ($uploadDirectory === '')
		{
			return null;
		}

		$uploadPathPrefix = '/' . $uploadDirectory . '/';

		return str_starts_with($normalizedPath, $uploadPathPrefix) ? $normalizedPath : null;
	}

	private function encodePath(string $path): string
	{
		return implode('/', array_map('rawurlencode', explode('/', $path)));
	}
}
