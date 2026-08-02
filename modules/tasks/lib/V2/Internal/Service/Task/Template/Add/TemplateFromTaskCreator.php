<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Template\Add;

use Bitrix\Tasks\Control\Exception\TemplateAddException;
use Bitrix\Tasks\V2\Internal\Access\Service\TemplateAccessService;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Service\Task\CopyFileService;
use Bitrix\Tasks\V2\Internal\Repository\RelatedTaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\Template\RelatedTaskTemplateRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Template;
use Bitrix\Tasks\V2\Internal\Service\AddTemplateService;
use Bitrix\Tasks\V2\Internal\Service\CheckList\TaskCheckListToTemplateService;
use Bitrix\Tasks\V2\Internal\Service\Task\Template\Convert\Config\ConvertConfig;
use Bitrix\Tasks\V2\Internal\Service\Task\Template\Add\Config\AddConfig;
use Bitrix\Tasks\V2\Internal\Service\Task\Template\Convert\ToTemplateConverter;

class TemplateFromTaskCreator
{
	public function __construct(
		private readonly TemplateAccessService $templateAccessService,
		private readonly AddTemplateService $addTemplateService,
		private readonly CopyFileService $copyFileService,
		private readonly TaskCheckListToTemplateService $taskCheckListToTemplateService,
		private readonly RelatedTaskRepositoryInterface $relatedTaskRepository,
		private readonly RelatedTaskTemplateRepositoryInterface $relatedTaskTemplateRepository,
	)
	{
	}

	/**
	 * @throws TemplateAddException
	 */
	public function create(Entity\Task $task, AddConfig $config): Entity\Template
	{
		$taskId = (int)$task->getId();

		$template = (new ToTemplateConverter(new ConvertConfig($config->withReplication)))($task);

		if (!$this->templateAccessService->canSave($config->userId, $template))
		{
			throw new TemplateAddException('TemplateFromTaskCreator: no permissions to create template');
		}

		$template = $this->prepareAttachments($template, $config);

		$template = $this->addTemplateService->add(
			template: $template,
			config: new Template\Action\Add\Config\AddConfig(userId: $config->userId),
		);

		$templateId = $template->getId();
		if ($templateId === null)
		{
			throw new TemplateAddException('TemplateFromTaskCreator: template was not created');
		}

		if ($config->withCheckLists)
		{
			$this->taskCheckListToTemplateService->copy(
				taskId: $taskId,
				templateId: $templateId,
				userId: $config->userId,
			);
		}

		if ($config->withRelatedTasks)
		{
			$relatedTaskIds = $this->relatedTaskRepository->getRelatedTaskIds($taskId);

			if (!empty($relatedTaskIds))
			{
				$this->relatedTaskTemplateRepository->save($templateId, $relatedTaskIds);
			}
		}

		return $template;
	}

	private function prepareAttachments(Entity\Template $template, AddConfig $config): Entity\Template
	{
		[$fileIds, $description] = $this->copyFileService->copyAttachments(
			description: $template->description ?? '',
			userId: $config->userId,
			fileIds: $template->fileIds ?? [],
		);

		return $template->cloneWith([
			'fileIds' => $fileIds,
			'description' => $description,
		]);
	}
}
