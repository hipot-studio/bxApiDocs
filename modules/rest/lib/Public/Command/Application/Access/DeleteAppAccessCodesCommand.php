<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\Application\Access;

use Bitrix\Main;
use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Rest\Internal\Exception\Application\ApplicationNotFoundException;

class DeleteAppAccessCodesCommand extends Main\Command\AbstractCommand
{
	public function __construct(
		public readonly int $userId,
		public readonly string $clientId,
		public readonly array $codesToRemove,
	)
	{
	}

	/**
	 * @throws AccessDeniedException
	 * @throws ApplicationNotFoundException
	 * @throws PersistenceException
	 */
	protected function execute(): Main\Result
	{
		(new DeleteAppAccessCodesCommandHandler())($this);

		return new Main\Result();
	}

	public function toArray(): array
	{
		return [
			'userId' => $this->userId,
			'clientId' => $this->clientId,
			'codesToRemove' => $this->codesToRemove,
		];
	}
}
