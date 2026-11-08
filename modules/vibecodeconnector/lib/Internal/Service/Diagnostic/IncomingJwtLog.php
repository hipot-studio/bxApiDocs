<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Diagnostic;

final class IncomingJwtLog
{
	public const AUDIT_TYPE_ID = 'VIBECODECONNECTOR_INCOMING_JWT';
	private const MAX_TOKEN_LENGTH = 24576;
	private const MAX_SEGMENT_LENGTH = 8192;
	private const MAX_ACTION_LENGTH = 255;
	private const MAX_SOURCE_IP_LENGTH = 64;
	private const MAX_ALGORITHM_LENGTH = 32;
	private const MAX_KEY_ID_LENGTH = 128;
	private const MAX_ISSUER_LENGTH = 255;
	private const MAX_AUDIENCE_LENGTH = 255;
	private const MAX_AUDIENCE_ITEMS = 5;
	private const MAX_JWT_ID_LENGTH = 128;
	private const MAX_ORIGIN_LENGTH = 64;
	private const MAX_ERROR_CLASS_LENGTH = 255;

	/**
	 * @return array{header: array<string, string>, claims: array<string, mixed>}
	 */
	public function createTechnicalContext(string $jwt): array
	{
		[$header, $claims] = $this->decodeBestEffort($jwt);

		return $this->normalizeTechnicalContext([
			'header' => $header,
			'claims' => $claims,
		]);
	}

	public function record(
		bool $hasToken,
		bool $claimsTrusted,
		array $technicalContext,
		?\Throwable $error,
		string $action,
		string $sourceIp,
	): void {
		$technicalContext = $this->normalizeTechnicalContext($technicalContext);
		$payload = [
			'action' => $this->limitString($action, self::MAX_ACTION_LENGTH),
			'source_ip' => $this->limitString($sourceIp, self::MAX_SOURCE_IP_LENGTH),
			'has_token' => $hasToken,
			'claims_trusted' => $claimsTrusted && $error === null,
			'header' => $technicalContext['header'],
			'claims' => $technicalContext['claims'],
		];
		if ($error !== null)
		{
			$payload['error_class'] = $this->limitString($error::class, self::MAX_ERROR_CLASS_LENGTH);
		}

		\CEventLog::Log(
			$error === null ? \CEventLog::SEVERITY_INFO : \CEventLog::SEVERITY_SECURITY,
			self::AUDIT_TYPE_ID,
			'vibecodeconnector',
			$error === null ? 'OK' : 'FAIL',
			json_encode(
				$payload,
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
			),
		);
	}

	/**
	 * @return array{0: ?array<string, mixed>, 1: ?array<string, mixed>}
	 */
	private function decodeBestEffort(string $jwt): array
	{
		if ($jwt === '' || strlen($jwt) > self::MAX_TOKEN_LENGTH)
		{
			return [null, null];
		}

		$parts = explode('.', $jwt);
		if (count($parts) !== 3)
		{
			return [null, null];
		}

		return [
			$this->decodeSegment($parts[0]),
			$this->decodeSegment($parts[1]),
		];
	}

	private function decodeSegment(string $segment): ?array
	{
		if ($segment === '' || strlen($segment) > self::MAX_SEGMENT_LENGTH)
		{
			return null;
		}

		$padded = strtr($segment, '-_', '+/');
		$pad = strlen($padded) % 4;
		if ($pad > 0)
		{
			$padded .= str_repeat('=', 4 - $pad);
		}

		$json = base64_decode($padded, true);
		if ($json === false)
		{
			return null;
		}

		try
		{
			$decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
		}
		catch (\JsonException)
		{
			return null;
		}

		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * @return array{header: array<string, string>, claims: array<string, mixed>}
	 */
	private function normalizeTechnicalContext(array $context): array
	{
		$sourceHeader = is_array($context['header'] ?? null) ? $context['header'] : [];
		$sourceClaims = is_array($context['claims'] ?? null) ? $context['claims'] : [];
		$header = [];
		$claims = [];

		$this->copyLimitedString($header, 'alg', $sourceHeader['alg'] ?? null, self::MAX_ALGORITHM_LENGTH);
		$this->copyLimitedString($header, 'kid', $sourceHeader['kid'] ?? null, self::MAX_KEY_ID_LENGTH);
		$this->copyLimitedString($claims, 'iss', $sourceClaims['iss'] ?? null, self::MAX_ISSUER_LENGTH);
		$this->copyLimitedString($claims, 'jti', $sourceClaims['jti'] ?? null, self::MAX_JWT_ID_LENGTH);
		$this->copyLimitedString($claims, 'origin', $sourceClaims['origin'] ?? null, self::MAX_ORIGIN_LENGTH);

		$audience = $this->normalizeAudience($sourceClaims['aud'] ?? null);
		if ($audience !== null)
		{
			$claims['aud'] = $audience;
		}

		foreach (['iat', 'nbf', 'exp'] as $claimName)
		{
			if (isset($sourceClaims[$claimName]) && is_int($sourceClaims[$claimName]))
			{
				$claims[$claimName] = $sourceClaims[$claimName];
			}
		}

		return [
			'header' => $header,
			'claims' => $claims,
		];
	}

	private function copyLimitedString(array &$target, string $key, mixed $value, int $maxLength): void
	{
		if (is_string($value))
		{
			$target[$key] = $this->limitString($value, $maxLength);
		}
	}

	private function normalizeAudience(mixed $audience): string|array|null
	{
		if (is_string($audience))
		{
			return $this->limitString($audience, self::MAX_AUDIENCE_LENGTH);
		}

		if (!is_array($audience))
		{
			return null;
		}

		$result = [];
		foreach ($audience as $item)
		{
			if (!is_string($item))
			{
				continue;
			}

			$result[] = $this->limitString($item, self::MAX_AUDIENCE_LENGTH);
			if (count($result) >= self::MAX_AUDIENCE_ITEMS)
			{
				break;
			}
		}

		return $result;
	}

	private function limitString(string $value, int $maxLength): string
	{
		return strlen($value) <= $maxLength ? $value : substr($value, 0, $maxLength);
	}
}
