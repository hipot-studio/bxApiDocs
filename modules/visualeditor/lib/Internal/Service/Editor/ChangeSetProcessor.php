<?php

namespace Bitrix\VisualEditor\Internal\Service\Editor;

use Bitrix\Main\Result;
use Bitrix\VisualEditor\Public\Adapter\EditorCapabilities;

final class ChangeSetProcessor
{
	public function process(
		string $baseline,
		array $changeSet,
		EditorCapabilities $capabilities = new EditorCapabilities(),
	): Result
	{
		return (new ChangeSetApplier())->apply($baseline, $changeSet, $capabilities);
	}
}
