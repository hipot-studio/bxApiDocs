<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\Application;

use Bitrix\Main;
use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Rest\Internal\Exception\Application\ApplicationNotFoundException;

class UninstallLocalAppCommand extends Main\Command\AbstractCommand
{
	public function __construct(
		public readonly int $userId,
		public readonly string $clientId,
	)
	{
	}

	/**
	 * @throws PersistenceException
	 * @throws ObjectNotFoundException
	 * @throws AccessDeniedException
	 * @throws ApplicationNotFoundException
	 */
	protected function execute(): Main\Result
	{
		$result = new Main\Result();

		$data = (new UninstallLocalAppCommandHandler())($this);
		$result->setData([$data]);

		return $result;
	}

	public function toArray(): array
	{
		return [
			'userId' => $this->userId,
			'clientId' => $this->clientId,
		];
	}
}
