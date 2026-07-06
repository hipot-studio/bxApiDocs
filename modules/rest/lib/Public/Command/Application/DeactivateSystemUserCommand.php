<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\Application;

use Bitrix\Main;

class DeactivateSystemUserCommand extends Main\Command\AbstractCommand
{
	public function __construct(
		public readonly int $appId,
	)
	{
	}

	protected function execute(): Main\Result
	{
		$result = new Main\Result();

		try
		{
			return (new DeactivateSystemUserCommandHandler())($this);
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
		];
	}
}