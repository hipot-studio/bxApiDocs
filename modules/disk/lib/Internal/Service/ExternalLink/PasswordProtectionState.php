<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\ExternalLink;

final class PasswordProtectionState
{
	public function __construct(
		public readonly bool $captchaRequired,
		public readonly ?string $captchaSid,
	)
	{
	}
}
