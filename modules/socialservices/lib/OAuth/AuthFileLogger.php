<?php

namespace Bitrix\Socialservices\OAuth;

use Bitrix\Main\Diag\FileLogger;

final class AuthFileLogger extends FileLogger
{
	private const SENSITIVE_KEYS = [
		'access_token',
		'refresh_token',
		'id_token',
		'client_secret',
		'oauth_verifier',
		'code_verifier',
		'code',
		'email',
		'first_name',
		'last_name',
		'name',
		'phone',
		'personal_phone',
	];

	public function __construct(string $fileName, private readonly string $serviceId, ?int $maxSize = null)
	{
		parent::__construct($fileName, $maxSize);
	}

	protected function interpolate(): string
	{
		$this->context = [
			'message' => (string)$this->message,
			'service_id' => $this->serviceId,
			... $this->sanitizeContext($this->context),
		];

		return parent::interpolate();
	}

	private function sanitizeContext(array $context): array
	{
		foreach ($context as $key => $value)
		{
			if ($this->isSensitiveKey((string)$key))
			{
				$context[$key] = '[REDACTED]';
			}
			elseif (is_array($value))
			{
				$context[$key] = $this->sanitizeContext($value);
			}
		}

		return $context;
	}

	private function isSensitiveKey(string $key): bool
	{
		$key = mb_strtolower($key);
		$key = str_replace(['-', '.'], '_', $key);

		return in_array($key, self::SENSITIVE_KEYS, true);
	}
}
