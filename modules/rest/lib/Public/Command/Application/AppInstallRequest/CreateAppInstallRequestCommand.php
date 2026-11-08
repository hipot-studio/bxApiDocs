<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\Application\AppInstallRequest;

use Bitrix\Main;
use Bitrix\Rest\Internal\Exception\Application\AppInstallRequest\AppInstallRequestException;

class CreateAppInstallRequestCommand extends Main\Command\AbstractCommand
{
	public function __construct(
		public readonly string $appCode,
		public readonly string $appVersion,
		public readonly int $userId,
		public readonly ?string $checkHash = null,
		public readonly ?string $installHash = null,
	)
	{
	}

	protected function execute(): Main\Result
	{
		$result = new Main\Result();

		try
		{
			$data = (new CreateAppInstallRequestCommandHandler())($this);
			$result->setData([$data]);
		}
		catch (AppInstallRequestException $e)
		{
			$result->addError(new Main\Error($e->getMessage(), $e->getErrorCode()));
		}

		return $result;
	}

	public function toArray(): array
	{
		return [
			'appCode'     => $this->appCode,
			'appVersion'  => $this->appVersion,
			'userId'      => $this->userId,
			'checkHash'   => $this->checkHash,
			'installHash' => $this->installHash,
		];
	}
}
