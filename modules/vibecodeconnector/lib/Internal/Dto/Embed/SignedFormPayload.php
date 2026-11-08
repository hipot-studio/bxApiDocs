<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Dto\Embed;

final class SignedFormPayload
{
	/**
	 * @param array<string, string> $fields
	 */
	public function __construct(
		public readonly string $actionUrl,
		public readonly array $fields,
		public readonly string $frameTitle = '',
		public readonly bool $clipboardWrite = false,
	) {
	}

	public function origin(): string
	{
		$parts = parse_url($this->actionUrl);
		if (!is_array($parts) || ($parts['scheme'] ?? '') === '' || ($parts['host'] ?? '') === '')
		{
			return '';
		}

		$origin = $parts['scheme'] . '://' . $parts['host'];

		return isset($parts['port']) ? $origin . ':' . $parts['port'] : $origin;
	}
}
