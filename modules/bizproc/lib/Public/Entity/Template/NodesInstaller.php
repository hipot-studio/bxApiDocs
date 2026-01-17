<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Entity\Template;

class NodesInstaller
{
	public function shouldInstall(): bool
	{
		return true;
	}

	public function getModifiedTime(): int
	{
		return time();
	}
}
