<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity;

enum PrivacyType: string
{
	case Open = 'open';
	case Closed = 'closed';

	// Grid-only legacy scrum privacy code. Do not use in project DTO or write-path.
	public const LEGACY_SCRUM_SECRET = 'secret';

	public static function fromLegacyFlags(string|bool|null $visible, string|bool|null $opened): self
	{
		$isVisible = $visible === true || $visible === 'Y';
		$isOpened = $opened === true || $opened === 'Y';

		if ($isVisible && $isOpened)
		{
			return self::Open;
		}

		return self::Closed;
	}
}
