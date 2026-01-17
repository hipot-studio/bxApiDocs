<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Tasks\Control;
use Bitrix\Tasks\V2\Internal\Access\Service\TemplateAccessService;
use Bitrix\Tasks\V2\Internal\Entity\Template;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\AccessDeniedException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\InvalidIdentifierException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\NotFoundException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\MakeTaskRecurringDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Mapper\ReplicateParamsMapper;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Mapper\TemplateMapper;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\TemplateControlMapper;
use Bitrix\Tasks\V2\Internal\Repository\TaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateRepositoryInterface;

class TemplateService
{
	public function __construct(
		private readonly TemplateAccessService $accessService,
		private readonly TaskRepositoryInterface $taskRepository,
		private readonly TemplateRepositoryInterface $templateRepository,
		private readonly TemplateMapper $templateMapper,
		private readonly ReplicateParamsMapper $replicateParamsMapper,
		private readonly TemplateControlMapper $templateControlMapper,
	)
	{
	}

	/**
	 * @throws AccessDeniedException
	 * @throws ArgumentException
	 * @throws Control\Exception\TemplateAddException
	 * @throws Control\Exception\TemplateUpdateException
	 * @throws InvalidIdentifierException
	 * @throws NotFoundException
	 * @throws NotImplementedException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function ensureRecurringTemplate(MakeTaskRecurringDto $dto, int $userId): void
	{
		if ($dto->taskId <= 0)
		{
			throw new InvalidIdentifierException();
		}

		$template = $this->templateRepository->getByTaskId($dto->taskId);

		$replicateParams = $this->replicateParamsMapper->convertFromDto($dto);

		if ($template)
		{
			$newTemplate = new Template(
				id: $template->id,
				replicate: true,
				replicateParams: $replicateParams,
			);
		}
		else
		{
			$task = $this->taskRepository->getById($dto->taskId);
			if (!$task)
			{
				throw new NotFoundException();
			}

			$newTemplate = $this->templateMapper->convertFromRecurringTask($task, $replicateParams);
		}

		if (!$this->accessService->canSave($userId, $newTemplate))
		{
			throw new AccessDeniedException();
		}

		$templateControl = $this->getTemplateControl($userId);

		$fields = $this->templateControlMapper->mapToControl($newTemplate);

		$newTemplate->getId()
			? $templateControl->update($newTemplate->id, $fields)
			: $templateControl->add($fields)
		;
	}

	protected function getTemplateControl(int $userId): Control\Template
	{
		return new Control\Template($userId);
	}
}
