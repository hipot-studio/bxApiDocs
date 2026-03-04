<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Control\Exception\TemplateAddException;
use Bitrix\Tasks\Control\Exception\TemplateNotFoundException;
use Bitrix\Tasks\V2\Internal\Access\Service\TemplateAccessService;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Service\Task\CopyFileService;
use Bitrix\Tasks\V2\Internal\Service\AddTemplateService;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Config\AddConfig;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Copy\Config\CopyConfig;
use Bitrix\Tasks\V2\Public\Provider\Params\Template\TemplateParams;
use Bitrix\Tasks\V2\Public\Provider\Template\TemplateProvider;

class CopyTemplateService
{
	public function __construct(
		private readonly TemplateAccessService $templateAccessService,
		private readonly CopyFileService $copyFileService,
		private readonly AddTemplateService $addTemplateService,
		private readonly TemplateProvider $templateProvider,
	)
	{

	}

	/**
	 * @throws TemplateAddException
	 * @throws TemplateNotFoundException
	 */
	public function copy(Entity\Template $templateData, CopyConfig $config): ?Entity\Template
	{
		$templateData = $this->clearId($templateData);
		if (!$this->templateAccessService->canSave($config->userId, $templateData))
		{
			throw new TemplateAddException(
				Loc::getMessage('TASKS_COPY_TEMPLATE_SERVICE_ACCESS_DENIED')
			);
		}

		$preparedTemplateData = $this->prepareAttachments($templateData, $config);

		$template = $this->addTemplateService->add(
			template: $preparedTemplateData,
			config: new AddConfig(userId: $config->userId),
		);

		$template = $this->templateProvider->get(new TemplateParams(
			templateId: $template->id,
			userId: $config->userId,
		));

		if ($template === null)
		{
			throw new TemplateNotFoundException(
				Loc::getMessage('TASKS_COPY_TEMPLATE_SERVICE_TEMPLATE_NOT_FOUND')
			);
		}

		return $template;
	}

	private function clearId(Entity\Template $templateData): Entity\Template
	{
		return $templateData->cloneWith(['id' => null]);
	}

	private function prepareAttachments(Entity\Template $templateData, CopyConfig $config): Entity\Template
	{
		[$fileIds, $description] = $this->copyFileService->copyAttachments(
			description:     $templateData->description ?? '',
			userId:          $config->userId,
			fileIds:         $templateData->fileIds ?? [],
		);

		return $templateData->cloneWith([
			'fileIds' => $fileIds,
			'description' => $description,
		]);
	}
}
