<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service;

use Bitrix\Tasks\Control\Exception\TaskNotExistsException;
use Bitrix\Tasks\V2\Internal\Access\Service\MemberAccessService;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\AccessDeniedException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\InvalidIdentifierException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\NotFoundException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Member\AddAccomplicesDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Member\AddAuditorsDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Member\DeleteAccomplicesDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Member\DeleteAuditorsDto;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;

class MemberService
{
	public function __construct(
		private readonly MemberAccessService $accessService,
		private readonly \Bitrix\Tasks\V2\Internal\Service\Task\MemberService $memberService,
	)
	{
	}

	/**
	 * @throws AccessDeniedException
	 * @throws InvalidIdentifierException
	 * @throws NotFoundException
	 */
	public function addAccomplices(AddAccomplicesDto $dto, int $userId): void
	{
		if ($dto->taskId <= 0)
		{
			throw new InvalidIdentifierException();
		}

		if (!$this->accessService->canAddAccomplices($userId, $dto->taskId))
		{
			throw new AccessDeniedException();
		}

		$config = new UpdateConfig($userId);

		try
		{
			$this->memberService->addAccomplices($dto->taskId, $dto->accompliceIds, $config);
		}
		catch (TaskNotExistsException)
		{
			throw new NotFoundException();
		}
	}

	/**
	 * @throws AccessDeniedException
	 * @throws InvalidIdentifierException
	 * @throws NotFoundException
	 */
	public function addAuditors(AddAuditorsDto $dto, int $userId): void
	{
		if ($dto->taskId <= 0)
		{
			throw new InvalidIdentifierException();
		}

		if (!$this->accessService->canAddAuditors($userId, $dto->taskId))
		{
			throw new AccessDeniedException();
		}

		$config = new UpdateConfig($userId);

		try
		{
			$this->memberService->addAuditors($dto->taskId, $dto->auditorIds, $config);
		}
		catch (TaskNotExistsException)
		{
			throw new NotFoundException();
		}
	}

	/**
	 * @throws AccessDeniedException
	 * @throws InvalidIdentifierException
	 * @throws NotFoundException
	 */
	public function deleteAccomplices(DeleteAccomplicesDto $dto, int $userId): void
	{
		if ($dto->taskId <= 0)
		{
			throw new InvalidIdentifierException();
		}

		if (!$this->accessService->canDeleteAccomplices($userId, $dto->taskId))
		{
			throw new AccessDeniedException();
		}

		$config = new UpdateConfig($userId);

		try
		{
			$this->memberService->deleteAccomplices($dto->taskId, $dto->accompliceIds, $config);
		}
		catch (TaskNotExistsException)
		{
			throw new NotFoundException();
		}
	}

	/**
	 * @throws AccessDeniedException
	 * @throws InvalidIdentifierException
	 * @throws NotFoundException
	 */
	public function deleteAuditors(DeleteAuditorsDto $dto, int $userId): void
	{
		if ($dto->taskId <= 0)
		{
			throw new InvalidIdentifierException();
		}

		if (!$this->accessService->canDeleteAuditors($userId, $dto->taskId))
		{
			throw new AccessDeniedException();
		}

		$config = new UpdateConfig($userId);

		try
		{
			$this->memberService->deleteAuditors($dto->taskId, $dto->auditorIds, $config);
		}
		catch (TaskNotExistsException)
		{
			throw new NotFoundException();
		}
	}
}
