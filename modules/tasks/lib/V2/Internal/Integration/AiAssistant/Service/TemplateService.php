<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Tasks\Control;
use Bitrix\Tasks\Feature\RegularTaskTypesFeature;
use Bitrix\Tasks\Replication\Template\Enum\RegularityType;
use Bitrix\Tasks\V2\Internal\Access\Service\TemplateAccessService;
use Bitrix\Tasks\V2\Internal\Entity\Template;
use Bitrix\Tasks\V2\Internal\Entity\Template\ReplicateParams;
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

		$replicateParams = $this->mapReplicateParams($dto, $userId, $template);
		$regularityType = $this->resolveRegularityType($dto);

		if ($template)
		{
			$newTemplate = new Template(
				id: $template->id,
				replicate: true,
				replicateParams: $replicateParams,
				regularityType: $regularityType,
				matchesWorkTime: $dto->matchesWorkTime,
			);
		}
		else
		{
			$task = $this->taskRepository->getById($dto->taskId);
			if (!$task)
			{
				throw new NotFoundException();
			}

			$newTemplate = $this->templateMapper->convertFromRecurringTask(
				$task,
				$replicateParams,
				$dto,
				$regularityType,
			);
		}

		if (!$this->accessService->canSave($userId, $newTemplate))
		{
			throw new AccessDeniedException();
		}

		$templateControl = $this->getTemplateControl($userId);

		$fields = $this->templateControlMapper->mapToControl($newTemplate);
		$fields = $this->mapRegularityFieldsToControl($fields);

		$newTemplate->getId()
			? $templateControl->update($newTemplate->id, $fields)
			: $templateControl->add($fields)
		;
	}

	public function updateRecurringTemplate(int $templateId, MakeTaskRecurringDto $dto, int $userId): void
	{
		if ($templateId <= 0)
		{
			throw new InvalidIdentifierException();
		}

		$existingTemplate = $this->templateRepository->getById($templateId);
		if ($existingTemplate === null)
		{
			throw new NotFoundException();
		}

		$template = new Template(
			id: $templateId,
			replicate: true,
			replicateParams: $this->mapReplicateParams($dto, $userId, $existingTemplate),
			regularityType: $this->resolveRegularityType($dto),
			matchesWorkTime: $dto->matchesWorkTime,
		);

		if (!$this->accessService->canSave($userId, $template))
		{
			throw new AccessDeniedException();
		}

		$fields = $this->templateControlMapper->mapToControl($template);
		$fields = $this->mapRegularityFieldsToControl($fields);

		$this->getTemplateControl($userId)->update($templateId, $fields);
	}

	private function mapReplicateParams(
		MakeTaskRecurringDto $dto,
		int $userId,
		?Template $template,
	): ReplicateParams
	{
		$params = $this->replicateParamsMapper->convertFromDto($dto, $userId);
		if ($dto->deadlineSpecified || $dto->regularityType === RegularityType::Deadline || !$template?->replicateParams)
		{
			return $params;
		}

		return ReplicateParams::mapFromArray([
			...$params->toArray(),
			'deadlineOffset' => $template->replicateParams->deadlineOffset,
			'deadlineType' => $template->replicateParams->deadlineType,
		]);
	}

	protected function getTemplateControl(int $userId): Control\Template
	{
		return new Control\Template($userId);
	}

	/**
	 * With the release flag off `REPLICATE_TYPE` must stay NULL: for the scheduler `0` is not NULL and
	 * switches monthly and yearly rules to the unified working-days calculation.
	 */
	private function resolveRegularityType(MakeTaskRecurringDto $dto): ?RegularityType
	{
		return Feature::isEnabled(RegularTaskTypesFeature::class) ? $dto->regularityType : null;
	}

	private function mapRegularityFieldsToControl(array $fields): array
	{
		if (array_key_exists('REGULARITY_TYPE', $fields))
		{
			$fields['REPLICATE_TYPE'] = $fields['REGULARITY_TYPE'];
			unset($fields['REGULARITY_TYPE']);
		}
		elseif (!Feature::isEnabled(RegularTaskTypesFeature::class))
		{
			$fields['REPLICATE_TYPE'] = null;
		}

		if (array_key_exists('MATCHES_WORK_TIME', $fields))
		{
			$fields['MATCH_WORK_TIME'] = $fields['MATCHES_WORK_TIME'];
			unset($fields['MATCHES_WORK_TIME']);
		}

		return $fields;
	}
}
