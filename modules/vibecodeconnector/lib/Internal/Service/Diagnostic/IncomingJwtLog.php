<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Diagnostic;

final class IncomingJwtLog
{
	public const AUDIT_TYPE_ID = 'VIBECODECONNECTOR_INCOMING_JWT';

	public function record(
		string $jwt,
		?\Throwable $error,
		string $action,
		string $sourceIp,
	): void {
		[$header, $claims] = $this->decodeBestEffort($jwt);

		if ($error === null)
		{
			$payload = [
				'action' => $action,
				'source_ip' => $sourceIp,
				'iss' => is_array($claims) ? ($claims['iss'] ?? null) : null,
				'jti' => is_array($claims) ? ($claims['jti'] ?? null) : null,
			];
		}
		else
		{
			$payload = [
				'action' => $action,
				'source_ip' => $sourceIp,
				'has_token' => $jwt !== '',
				'header' => $header,
				'claims' => $claims,
				'error' => $error->getMessage(),
			];
		}

		\CEventLog::Log(
			$error === null ? \CEventLog::SEVERITY_INFO : \CEventLog::SEVERITY_SECURITY,
			self::AUDIT_TYPE_ID,
			'vibecodeconnector',
			$error === null ? 'OK' : 'FAIL',
			json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		);
	}

	/**
	 * @return array{0: ?array<string, mixed>, 1: ?array<string, mixed>}
	 */
	private function decodeBestEffort(string $jwt): array
	{
		if ($jwt === '')
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

		$decoded = json_decode($json, true);

		return is_array($decoded) ? $decoded : null;
	}
}
