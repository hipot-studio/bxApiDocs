<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\Application\AppInstallRequest;

use Bitrix\Main;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class RejectAppInstallRequestCommand extends Main\Command\AbstractCommand
{
	public function __construct(
		public readonly int $requestId,
		public readonly int $adminId,
	)
	{
	}

	protected function execute(): Main\Result
	{
		$result = new Main\Result();

		$found = (new RejectAppInstallRequestCommandHandler())($this);
		if (!$found)
		{
			$result->addError(new Main\Error(
				Loc::getMessage('REST_REJECT_APP_INSTALL_REQUEST_ERROR_NOT_FOUND'),
				'not_found',
			));
		}

		return $result;
	}

	public function toArray(): array
	{
		return [
			'requestId' => $this->requestId,
			'adminId'   => $this->adminId,
		];
	}
}
