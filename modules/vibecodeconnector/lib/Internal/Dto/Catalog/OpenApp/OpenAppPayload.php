<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Dto\Catalog\OpenApp;

final readonly class OpenAppPayload
{
	/**
	 * @param array<string, scalar|null> $fields
	 */
	public function __construct(
		public string $actionUrl,
		public array $fields,
	) {
	}
}
