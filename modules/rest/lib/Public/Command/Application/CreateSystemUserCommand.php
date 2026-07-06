<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\Application;

use Bitrix\Main;

class CreateSystemUserCommand extends Main\Command\AbstractCommand
{
	public function __construct(
		public readonly int $appId,
		public readonly int $userId,
	)
	{
	}

	protected function execute(): Main\Result
	{
		$result = new Main\Result();

		try
		{
			return (new CreateSystemUserCommandHandler())($this);
		}
		catch (\Throwable $e)
		{
			$result->addError(new Main\Error($e->getMessage(), $e->getCode()));
		}

		return $result;
	}

	public function toArray(): array
	{
		return [
			'appId' => $this->appId,
			'userId' => $this->userId,
		];
	}
}
