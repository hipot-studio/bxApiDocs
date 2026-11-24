<?php

namespace Bitrix\AI\Image\Url;

use Bitrix\Main\Web\Uri;
use Throwable;

final class SecureImageUrlLoader
{
	private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];
	private const FORBIDDEN_RANGES = [
		'0.0.0.0/8',
		'10.0.0.0/8',
		'100.64.0.0/10',
		'127.0.0.0/8',
		'169.254.0.0/16',
		'172.16.0.0/12',
		'192.0.0.0/24',
		'192.0.2.0/24',
		'192.88.99.0/24',
		'192.168.0.0/16',
		'198.18.0.0/15',
		'198.51.100.0/24',
		'203.0.113.0/24',
		'224.0.0.0/4',
		'240.0.0.0/4',
	];

	private array $forbiddenRanges;

	public function __construct(
		private readonly RuntimeInterface $runtime,
		array $extraForbiddenRanges = [],
		private readonly int $redirectLimit = 5,
	)
	{
		$this->forbiddenRanges = array_merge(self::FORBIDDEN_RANGES, $extraForbiddenRanges);
	}

	public function save(mixed $url, string $moduleId): ?int
	{
		try
		{
			$currentUrl = $this->normalizeAbsoluteUrl($url);
			if ($currentUrl === null || $this->redirectLimit < 0)
			{
				return null;
			}

			$initialHost = (new Uri($currentUrl))->getHost();
			$currentTarget = null;
			if (filter_var($initialHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)
			{
				if (!$this->isAllowedIpv4($initialHost))
				{
					return null;
				}
				$currentTarget = ['ip' => $initialHost];
			}

			if ($this->runtime->usesProxy())
			{
				return null;
			}

			$visited = [];
			$redirectCount = 0;

			while (true)
			{
				if (isset($visited[$currentUrl]))
				{
					return null;
				}
				$visited[$currentUrl] = true;

				if ($currentTarget === null)
				{
					$currentTarget = $this->prepareTarget($currentUrl);
					if ($currentTarget === null)
					{
						return null;
					}
				}

				$response = $this->runtime->request($currentUrl, $currentTarget['ip']);
				if (!$this->isValidResponse($response, $currentUrl, $currentTarget['ip']))
				{
					return null;
				}

				$status = $response['status'];
				if (in_array($status, self::REDIRECT_STATUSES, true))
				{
					if ($redirectCount >= $this->redirectLimit)
					{
						return null;
					}

					$location = $this->getHeader($response['headers'], 'location');
					if ($location === null || $location === '')
					{
						return null;
					}

					$currentUrl = $this->resolveRedirect($currentUrl, $location);
					if ($currentUrl === null || isset($visited[$currentUrl]))
					{
						return null;
					}

					$currentTarget = null;
					$redirectCount++;
					continue;
				}

				if ($status < 200 || $status >= 300 || $response['body'] === '')
				{
					return null;
				}

				return $this->runtime->checkAndSaveImage(
					$response['body'],
					$moduleId,
					$this->getSourceName($currentUrl),
				);
			}
		}
		catch (Throwable)
		{
			return null;
		}
	}

	private function normalizeAbsoluteUrl(mixed $url): ?string
	{
		if (!is_string($url) || $url === '' || preg_match('/[\x00-\x20\x7f]/', $url))
		{
			return null;
		}

		if (!preg_match('#^https?://#i', $url))
		{
			return null;
		}

		$authorityEnd = strcspn($url, '/?#', strpos($url, '://') + 3);
		$authority = substr($url, strpos($url, '://') + 3, $authorityEnd);
		if ($authority === '' || str_contains($authority, '%') || str_contains($authority, '\\'))
		{
			return null;
		}

		$uri = new Uri($url);
		$scheme = $uri->getScheme();
		$host = $uri->getHost();
		$port = $uri->getPort();

		if (
			!in_array($scheme, ['http', 'https'], true)
			|| $host === ''
			|| $uri->getUserInfo() !== ''
			|| $port === null
			|| $port < 1
			|| $port > 65535
		)
		{
			return null;
		}

		$host = strtolower($host);
		if (str_ends_with($host, '.'))
		{
			$host = substr($host, 0, -1);
		}
		$host = $this->normalizeHost($host);

		if ($host === null || !$this->isUnambiguousHost($host))
		{
			return null;
		}

		$portPart = '';
		if (($scheme === 'http' && $port !== 80) || ($scheme === 'https' && $port !== 443))
		{
			$portPart = ':' . $port;
		}

		$path = $uri->getPath();
		if ($path === '')
		{
			$path = '/';
		}
		elseif (!str_starts_with($path, '/'))
		{
			return null;
		}
		$path = $this->removeDotSegments($path);

		$query = $uri->getQuery();

		return $scheme . '://' . $host . $portPart . $path . ($query !== '' ? '?' . $query : '');
	}

	private function removeDotSegments(string $path): string
	{
		$segments = explode('/', $path);
		$normalized = [];
		$lastIndex = count($segments) - 1;

		foreach ($segments as $index => $segment)
		{
			if ($segment === '.')
			{
				if ($index === $lastIndex)
				{
					$normalized[] = '';
				}

				continue;
			}
			if ($segment === '..')
			{
				if (count($normalized) > 1)
				{
					array_pop($normalized);
				}
				if ($index === $lastIndex)
				{
					$normalized[] = '';
				}

				continue;
			}

			$normalized[] = $segment;
		}

		$result = implode('/', $normalized);

		return $result === '' ? '/' : $result;
	}

	private function isUnambiguousHost(string $host): bool
	{
		if ($host === '' || str_contains($host, ':') || !preg_match('/^[a-z0-9._-]+$/D', $host))
		{
			return false;
		}

		if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)
		{
			return true;
		}

		if (
			preg_match('/^(?:0x[0-9a-f]+|[0-9]+)$/iD', $host)
			|| preg_match('/^(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))+$/iD', $host)
		)
		{
			return false;
		}

		$labels = explode('.', $host);
		foreach ($labels as $label)
		{
			if (
				$label === ''
				|| strlen($label) > 63
				|| str_starts_with($label, '-')
				|| str_ends_with($label, '-')
			)
			{
				return false;
			}
		}

		return strlen($host) <= 253;
	}

	private function normalizeHost(string $host): ?string
	{
		$labels = explode('.', $host);
		foreach ($labels as &$label)
		{
			if (!preg_match('/[^\x00-\x7f]/', $label))
			{
				continue;
			}

			$errors = [];
			$label = \CBXPunycode::ToASCII($label, $errors);
			if (!is_string($label) || $label === '' || $errors !== [])
			{
				return null;
			}
		}
		unset($label);

		return strtolower(implode('.', $labels));
	}

	private function prepareTarget(string $url): ?array
	{
		$uri = new Uri($url);
		$host = $uri->getHost();
		if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)
		{
			return $this->isAllowedIpv4($host) ? ['ip' => $host] : null;
		}

		$result = $this->runtime->resolve($host);
		if (
			($result['success'] ?? false) !== true
			|| ($result['truncated'] ?? true) !== false
			|| !isset($result['addresses'])
			|| !is_array($result['addresses'])
			|| $result['addresses'] === []
		)
		{
			return null;
		}

		$addresses = array_values(array_unique($result['addresses']));
		foreach ($addresses as $address)
		{
			if (!is_string($address) || !$this->isAllowedIpv4($address))
			{
				return null;
			}
		}

		return ['ip' => $addresses[0]];
	}

	private function isAllowedIpv4(string $ip): bool
	{
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)
		{
			return false;
		}

		$ipValue = ip2long($ip);
		foreach ($this->forbiddenRanges as $range)
		{
			if (!is_string($range) || !preg_match('#^([^/]+)/(\d{1,2})$#D', $range, $matches))
			{
				continue;
			}

			$subnet = filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
			$prefix = (int)$matches[2];
			if ($subnet === false || $prefix < 0 || $prefix > 32)
			{
				continue;
			}

			$mask = $prefix === 0 ? 0 : (-1 << (32 - $prefix));
			if (($ipValue & $mask) === (ip2long($subnet) & $mask))
			{
				return false;
			}
		}

		return true;
	}

	private function isValidResponse(array $response, string $url, string $pinnedIp): bool
	{
		if (
			($response['success'] ?? false) !== true
			|| !isset($response['status'], $response['headers'], $response['body'])
			|| !is_int($response['status'])
			|| !is_array($response['headers'])
			|| !is_string($response['body'])
			|| ($response['effectiveIp'] ?? null) !== $pinnedIp
			|| !is_string($response['effectiveUrl'] ?? null)
		)
		{
			return false;
		}

		$effectiveUrl = $this->normalizeAbsoluteUrl($response['effectiveUrl']);

		return $effectiveUrl !== null && $effectiveUrl === $url;
	}

	private function resolveRedirect(string $baseUrl, string $location): ?string
	{
		if (preg_match('/[\x00-\x20\x7f]/', $location) || str_starts_with($location, '//'))
		{
			return null;
		}

		$resolved = (new Uri($location))->resolveRelativeUri(new Uri($baseUrl));

		return $this->normalizeAbsoluteUrl((string)$resolved);
	}

	private function getHeader(array $headers, string $name): ?string
	{
		foreach ($headers as $headerName => $value)
		{
			if (is_string($headerName) && strtolower($headerName) === $name && is_string($value))
			{
				return $value;
			}
		}

		return null;
	}

	private function getSourceName(string $url): string
	{
		$name = basename(rawurldecode((new Uri($url))->getPath()));

		return $name !== '' && $name !== '.' && $name !== '..' ? $name : 'image';
	}
}
