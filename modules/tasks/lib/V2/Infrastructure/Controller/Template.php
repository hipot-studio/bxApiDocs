<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Config\AddConfig;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete\Config\DeleteConfig;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Public\Command\Template\AddTemplateCommand;
use Bitrix\Tasks\V2\Public\Command\Template\DeleteTemplateCommand;
use Bitrix\Tasks\V2\Public\Command\Template\UpdateTemplateCommand;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Access\Template\Permission;
use Bitrix\Tasks\V2\Public\Provider\Params\Template\TemplateParams;
use Bitrix\Tasks\V2\Public\Provider\Template\TemplateProvider;

class Template extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Template.get
	 */
	#[CloseSession]
	public function getAction(
		#[Permission\Read]
		Entity\Template $template,
		TemplateParams $templateSelect,
		TemplateProvider $templateProvider,
	): ?Entity\Template
	{
		return $templateProvider->get(
			new TemplateParams(
				templateId: $template->getId(),
				userId: $this->userId,
				group: $templateSelect->group,
				members: $templateSelect->members,
				checkLists: $templateSelect->checkLists,
				crm: $templateSelect->crm,
				tags: $templateSelect->tags,
				subTemplates: $templateSelect->subTemplates,
				userFields: $templateSelect->userFields,
				relatedTasks: $templateSelect->relatedTasks,
				permissions: $templateSelect->permissions,
				parent: $templateSelect->parent,
				checkTemplateAccess: false,
			),
		);
	}

	/**
	 * @restMethod tasks.V2.Template.add
	 */
	public function addAction(
		#[Permission\Add]
		Entity\Template $template,
		TemplateProvider $templateProvider,
	): ?Entity\Template
	{
		$result = (new AddTemplateCommand(
			template: $template,
			config: new AddConfig(userId: $this->userId),
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $templateProvider->get(new TemplateParams(templateId: $result->getId(), userId: $this->userId));

	}

	/**
	 * @restMethod tasks.V2.Template.update
	 */
	public function updateAction(
		#[Permission\Update]
		Entity\Template $template,
		TemplateProvider $templateProvider,
	): ?Entity\Template
	{
		$result = (new UpdateTemplateCommand(
			template: $template,
			config: new UpdateConfig(userId: $this->userId),
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $templateProvider->get(new TemplateParams(templateId: $result->getId(), userId: $this->userId));
	}

	/**
	 * @restMethod tasks.V2.Template.delete
	 */
	public function deleteAction(
		#[Permission\Delete]
		Entity\Template $template
	): ?bool
	{
		$result = (new DeleteTemplateCommand(
			templateId: $template->getId(),
			config: new DeleteConfig(userId: $this->userId),
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return true;
	}
}
