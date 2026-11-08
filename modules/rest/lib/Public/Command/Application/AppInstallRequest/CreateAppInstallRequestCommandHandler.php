<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\Application\AppInstallRequest;

use Bitrix\Rest\Internal\Entity\Application\AppInstallRequest\AppInstallRequest;
use Bitrix\Rest\Internal\Exception\Application\AppInstallRequest\AppInstallRequestException;
use Bitrix\Rest\Internal\Service\Application\AppInstallRequest\AppInstallRequestManager;

class CreateAppInstallRequestCommandHandler
{
	public function __construct(
		private readonly AppInstallRequestManager $manager = new AppInstallRequestManager(),
	)
	{
	}

	/**
	 * @throws AppInstallRequestException
	 */
	public function __invoke(CreateAppInstallRequestCommand $command): AppInstallRequest
	{
		return $this->manager->create(
			$command->appCode,
			$command->appVersion,
			$command->userId,
			$command->checkHash,
			$command->installHash,
		);
	}
}
