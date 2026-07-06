<?php

namespace Bitrix\Sign\Debug;

use Bitrix\Main\Diag\LogFormatter as BaseFormatter;

/**
 * Masks sensitive data in the context, then delegates to the core formatter.
 * {trace}, {exception}, {date}, {host}, {delimiter} are handled by the core LogFormatter.
 */
class SecretMaskingFormatter extends BaseFormatter
{
	/** Context key for the value passed to Logger::dump(). */
	public const PLACEHOLDER_DUMP = 'sign_dump';

	public function format($message, array $context = []): string
	{
		$this->maskSecrets($context);

		return parent::format($message, $context);
	}

	private function maskSecrets(array &$context): void
	{
		array_walk_recursive($context, static function (&$value, $key)
		{
			if (in_array($key, ['securityCode', 'token', 'pageToken']))
			{
				$value = substr($value, 0, 5) . ' ... <cut by logger>';
				return;
			}

			if (is_string($value))
			{
				$strlen = strlen($value);
				switch (true)
				{
					case $strlen > 300 && substr($value, 0, 4) === "%PDF":
					case $strlen > 300 && substr($value, 1, 3) === 'PNG':
					case $strlen > 300 && self::isContentLooksLikeBase64($value):
						$value = substr($value, 0, 50) . ' ... <content cut by logger>';
						break;
					case $strlen > 5000:
						$value = substr($value, 0, 300) . ' ... <content cut by logger>';
						break;
				}
			}
		});
	}

	private static function isContentLooksLikeBase64(string $data, bool $exact = true): bool
	{
		$regexp = '(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=)?';
		$regexp = $exact
			? '^'.$regexp.'$'
			: $regexp
		;
		return preg_match('/'.$regexp.'/', $data) === 1;
	}
}
