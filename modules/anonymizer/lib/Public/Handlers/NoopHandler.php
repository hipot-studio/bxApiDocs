<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Public\Handlers;

use Bitrix\Anonymizer\Public\Context\QuestContext;

/**
 * Handler that intentionally does nothing.
 * Useful for technical flows where only request lifecycle/result persistence matters.
 */
final class NoopHandler extends QuestHandler
{
	public function onResponse(QuestContext $context): void
	{
	}

	public function onError(QuestContext $context): void
	{
	}
}
