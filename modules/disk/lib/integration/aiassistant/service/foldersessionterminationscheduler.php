<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Service;

use Bitrix\Disk\Folder;
use Bitrix\Disk\Security\SecurityContext;

interface FolderSessionTerminationScheduler
{
	public function prepare(Folder $folder, SecurityContext $securityContext): FolderSessionTerminationPlan;

	public function schedule(FolderSessionTerminationPlan $plan): void;
}
