<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\Application\Access;

use Bitrix\Main;
use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Rest\Internal\Exception\Application\ApplicationNotFoundException;

class ResetAppAccessCommand extends Main\Command\AbstractCommand
{
	public function __construct(
		public readonly int $userId,
		public readonly string $clientId,
	)
	{
	}

	/**
	 * @throws PersistenceException
	 * @throws AccessDeniedException
	 * @throws ApplicationNotFoundException
	 */
	protected function execute(): Main\Result
	{
		(new ResetAppAccessCommandHandler())($this);

		return new Main\Result();
	}

	public function toArray(): array
	{
		return [
			'userId' => $this->userId,
			'clientId' => $this->clientId,
		];
	}
}
