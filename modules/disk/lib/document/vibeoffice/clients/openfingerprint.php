<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Clients;

use Bitrix\Disk\Document\Vibeoffice\Filters\EgressUrlSignatureCheck;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Web\Json;

/**
 * Digest of everything an open send is bound to. Two sends of one token may differ only in the values
 * of the egress authorization (vo_exp, vo_sig) of document.url; any other byte changes the digest.
 */
final class OpenFingerprint
{
	private const VERSION = 'vo-open-fingerprint-v1';
	private const MASKED_PARAMS = [
		EgressUrlSignatureCheck::PARAM_EXPIRES => '~vo_exp~',
		EgressUrlSignatureCheck::PARAM_SIGNATURE => '~vo_sig~',
	];

	/**
	 * @param string[] $parts tuple items in their fixed order
	 */
	public static function digest(array $parts): string
	{
		$tuple = self::lengthPrefixed(self::VERSION);
		foreach ($parts as $part)
		{
			$tuple .= self::lengthPrefixed($part);
		}

		return hash('sha256', $tuple);
	}

	public static function lengthPrefixed(string $value): string
	{
		return strlen($value) . ':' . $value;
	}

	/** JSON bytes of the body exactly as the protocol client sends them. */
	public static function encodeBody(array $body): ?string
	{
		try
		{
			return Json::encode($body);
		}
		catch (ArgumentException)
		{
			return null;
		}
	}

	/**
	 * Replaces only the values of vo_exp and vo_sig inside the single span of document.url with fixed
	 * markers. Null means the body cannot be masked unambiguously and must not be sent.
	 */
	public static function maskRawBody(string $rawBody, array $body): ?string
	{
		if (!is_array($body['document'] ?? null) || !array_key_exists('url', $body['document']))
		{
			return $rawBody;
		}
		$url = $body['document']['url'];
		if (!is_string($url))
		{
			return null;
		}
		$masked = self::maskUrl($url);
		$encodedUrl = self::encodeBody([$url]);
		$encodedMasked = $masked === null ? null : self::encodeBody([$masked]);
		if ($encodedUrl === null || $encodedMasked === null)
		{
			return null;
		}
		$span = substr($encodedUrl, 1, -1);
		if (substr_count($rawBody, $span) !== 1)
		{
			return null;
		}

		return str_replace($span, substr($encodedMasked, 1, -1), $rawBody);
	}

	private static function maskUrl(string $url): ?string
	{
		$fragmentAt = strpos($url, '#');
		$beforeFragment = $fragmentAt === false ? $url : substr($url, 0, $fragmentAt);
		$fragment = $fragmentAt === false ? '' : substr($url, $fragmentAt);
		$queryAt = strpos($beforeFragment, '?');
		if ($queryAt === false)
		{
			return $url;
		}

		$seen = [];
		$pairs = explode('&', substr($beforeFragment, $queryAt + 1));
		foreach ($pairs as $index => $pair)
		{
			$equalsAt = strpos($pair, '=');
			$name = $equalsAt === false ? $pair : substr($pair, 0, $equalsAt);
			if (isset(self::MASKED_PARAMS[$name]))
			{
				if ($equalsAt === false || isset($seen[$name]))
				{
					return null;
				}
				$seen[$name] = true;
				$pairs[$index] = $name . '=' . self::MASKED_PARAMS[$name];

				continue;
			}
			if (isset(self::MASKED_PARAMS[strtolower(rawurldecode(str_replace('+', ' ', $name)))]))
			{
				return null;
			}
		}
		if (count($seen) !== 0 && count($seen) !== count(self::MASKED_PARAMS))
		{
			return null;
		}

		return substr($beforeFragment, 0, $queryAt + 1) . implode('&', $pairs) . $fragment;
	}
}
