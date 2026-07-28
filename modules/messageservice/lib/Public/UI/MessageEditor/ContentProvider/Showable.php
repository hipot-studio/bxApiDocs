<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\UI\MessageEditor\ContentProvider;

/**
 * Provider that can determine its own visibility.
 * Editor::setContentProviders() checks instanceof Showable and skips providers where isShown() returns false.
 */
interface Showable
{
	public function isShown(): bool;
}
