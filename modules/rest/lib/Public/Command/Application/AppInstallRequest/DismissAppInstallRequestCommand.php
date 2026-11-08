<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\Application\AppInstallRequest;

use Bitrix\Main;

class DismissAppInstallRequestCommand extends Main\Command\AbstractCommand
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

		(new DismissAppInstallRequestCommandHandler())($this);

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
