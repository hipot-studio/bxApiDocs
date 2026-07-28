<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\UI\MessageEditor\Scene;

use Bitrix\MessageService\Public\UI\MessageEditor\Scene;

/**
 * Preferences are never saved for null scene.
 */
final class NullScene extends Scene
{
	public function getId(): string
	{
		return '';
	}
}
