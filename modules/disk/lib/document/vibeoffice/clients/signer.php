<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Clients;

use Bitrix\Main\Security\Random;

/**
 * Builds the VO1 HMAC signature headers for outgoing protocol-client requests.
 *
 * Canonical string (ALG-SIGN, NORMATIVE):
 *   canonical = join("\n", ["VO1", upper(method), escapedPath, rawQuery, ts, nonce, bodyHash])
 *   bodyHash  = hex(sha256(rawBody))      // empty body -> sha256("")
 *   sig       = "v1=" + hex(hmac_sha256(apiSecret, canonical))
 *
 * The signer is intentionally free of transport concerns: it only knows how to
 * build the canonical string and the 4 headers from the supplied inputs. ts and
 * nonce are injectable so the signature is deterministic in tests; in production
 * the caller passes time() and a crypto-random nonce.
 */
final class Signer
{
	public const HEADER_KEY = 'X-VO-Key';
	public const HEADER_TIMESTAMP = 'X-VO-Timestamp';
	public const HEADER_NONCE = 'X-VO-Nonce';
	public const HEADER_SIGNATURE = 'X-VO-Signature';

	private string $keyId;
	private string $apiSecret;

	public function __construct(string $keyId, string $apiSecret)
	{
		$this->keyId = $keyId;
		$this->apiSecret = $apiSecret;
	}

	/**
	 * Generates a crypto-random nonce (16 bytes -> 32 hex chars).
	 */
	public static function generateNonce(): string
	{
		return bin2hex(Random::getBytes(16));
	}

	/**
	 * Builds the 4 VO1 signature headers for a single request.
	 *
	 * @param string $method HTTP method (e.g. POST, DELETE)
	 * @param string $escapedPath percent-encoded path exactly as it goes on the wire
	 * @param string $rawQuery raw query string ("" if none)
	 * @param string $rawBody raw request body ("" if none)
	 * @param int $ts unix seconds
	 * @param string $nonce unique-per-request nonce
	 * @return array{X-VO-Key: string, X-VO-Timestamp: string, X-VO-Nonce: string, X-VO-Signature: string}
	 */
	public function buildHeaders(
		string $method,
		string $escapedPath,
		string $rawQuery,
		string $rawBody,
		int $ts,
		string $nonce
	): array
	{
		$canonical = $this->buildCanonicalString($method, $escapedPath, $rawQuery, $rawBody, $ts, $nonce);
		$signature = $this->sign($canonical);

		return [
			self::HEADER_KEY => $this->keyId,
			self::HEADER_TIMESTAMP => (string)$ts,
			self::HEADER_NONCE => $nonce,
			self::HEADER_SIGNATURE => $signature,
		];
	}

	/**
	 * Builds the canonical string per ALG-SIGN: 7 lines joined by "\n".
	 */
	public function buildCanonicalString(
		string $method,
		string $escapedPath,
		string $rawQuery,
		string $rawBody,
		int $ts,
		string $nonce
	): string
	{
		return implode("\n", [
			'VO1',
			strtoupper($method),
			$escapedPath,
			$rawQuery,
			(string)$ts,
			$nonce,
			self::hashBody($rawBody),
		]);
	}

	/**
	 * hex(sha256(rawBody)); for an empty body this is sha256("").
	 */
	public static function hashBody(string $rawBody): string
	{
		return hash('sha256', $rawBody);
	}

	/**
	 * sig = "v1=" + hex(hmac_sha256(apiSecret, canonical)).
	 */
	public function sign(string $canonical): string
	{
		return 'v1=' . hash_hmac('sha256', $canonical, $this->apiSecret);
	}
}
