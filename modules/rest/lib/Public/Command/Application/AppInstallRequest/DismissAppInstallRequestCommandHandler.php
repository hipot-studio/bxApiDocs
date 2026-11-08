<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\Application\AppInstallRequest;

use Bitrix\Rest\Internal\Service\Application\AppInstallRequest\AppInstallRequestManager;

class DismissAppInstallRequestCommandHandler
{
	public function __construct(
		private readonly AppInstallRequestManager $manager = new AppInstallRequestManager(),
	)
	{
	}

	public function __invoke(DismissAppInstallRequestCommand $command): void
	{
		$this->manager->dismissOtherAdmins($command->requestId, $command->adminId);
	}
}
