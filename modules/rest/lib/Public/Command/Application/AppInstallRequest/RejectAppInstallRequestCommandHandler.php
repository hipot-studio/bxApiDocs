<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\Application\AppInstallRequest;

use Bitrix\Rest\Internal\Service\Application\AppInstallRequest\AppInstallRequestManager;

class RejectAppInstallRequestCommandHandler
{
	public function __construct(
		private readonly AppInstallRequestManager $manager = new AppInstallRequestManager(),
	)
	{
	}

	public function __invoke(RejectAppInstallRequestCommand $command): bool
	{
		return $this->manager->reject($command->requestId, $command->adminId);
	}
}
