<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service;

use Bitrix\Tasks\V2\Internal\Access\Service\ResultAccessService;
use Bitrix\Tasks\V2\Internal\Entity\Result;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\AccessDeniedException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\InvalidIdentifierException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\AddResultDto;

class ResultService
{
	public function __construct(
		private readonly ResultAccessService $accessService,
		private readonly \Bitrix\Tasks\V2\Internal\Service\Task\ResultService $resultService,
	)
	{
	}

	/**
	 * @throws AccessDeniedException
	 * @throws InvalidIdentifierException
	 */
	public function add(AddResultDto $dto, int $userId): void
	{
		if ($dto->taskId <= 0)
		{
			throw new InvalidIdentifierException();
		}

		$result = new Result(
			taskId: $dto->taskId,
			text: $dto->text,
			author: new User($dto->authorId),
		);

		if (!$this->accessService->canSave($userId, $result))
		{
			throw new AccessDeniedException();
		}

		$this->resultService->create($result, $userId);
	}
}
