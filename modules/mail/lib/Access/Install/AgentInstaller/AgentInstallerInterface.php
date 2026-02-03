<?php

declare(strict_types=1);

namespace Bitrix\Mail\Access\Install\AgentInstaller;

interface AgentInstallerInterface
{
	public function install(): string;
}
