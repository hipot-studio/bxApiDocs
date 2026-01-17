<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Tasks\Service;

use Bitrix\Im\V2\Integration\Tasks\Access\ResultAccessService;
use Bitrix\Im\V2\Integration\Tasks\Exception\AccessDeniedException;
use Bitrix\Im\V2\Integration\Tasks\Exception\AddResultException;
use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Tasks\V2\Internal;
use Bitrix\Tasks\V2\Public\Command\Task\Result\AddResultCommand;

class ResultService
{
	public function __construct(
		private readonly ResultAccessService $accessService,
	)
	{
	}

	/**
	 * @throws AccessDeniedException
	 * @throws AddResultException
	 * @throws CommandException
	 * @throws CommandValidationException
	 */
	public function add(Internal\Entity\Result $result, int $userId): Internal\Entity\Result
	{
		if (!$this->accessService->canSave($userId, $result))
		{
			throw new AccessDeniedException();
		}

		/** @var Internal\Result\Result $commandResult */
		$commandResult = (new AddResultCommand($result, $userId))->run();

		if (!$commandResult->isSuccess())
		{
			$error = $commandResult->getError();

			throw new AddResultException($error->getMessage(), $error->getCode());
		}

		/** @var Internal\Entity\Result $addedResult */
		$addedResult = $commandResult->getObject();

		return $addedResult;
	}
}
