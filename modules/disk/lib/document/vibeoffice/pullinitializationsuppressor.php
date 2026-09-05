<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

final class PullInitializationSuppressor
{
	public static function suppressForDocumentEditor(bool $isDocumentEditor): void
	{
		if (self::shouldSuppress($isDocumentEditor, defined('BX_PULL_SKIP_INIT')))
		{
			define('BX_PULL_SKIP_INIT', true);
		}
	}

	public static function shouldSuppress(bool $isDocumentEditor, bool $hasExplicitPullInitializationPolicy): bool
	{
		return $isDocumentEditor && !$hasExplicitPullInitializationPolicy;
	}
}
