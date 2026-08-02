<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Agent;

use Bitrix\Bizproc\Public\Service\Template\NodesInstallerService;

abstract class SyncSystemNodesAgent
{
	abstract protected static function getSectionId(): string;

	public static function runAgent(): string
	{
		(new NodesInstallerService())->trySyncSection(static::getSectionId(), force: true);

		return static::class . '::runAgent();';
	}
}
