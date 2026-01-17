<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Template;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Main\Error;
use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\TemplateAccessController;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Access\Template\Permission;
use Bitrix\Tasks\V2\Public\Command\Template\CheckList\CompleteCheckListItemsCommand;
use Bitrix\Tasks\V2\Public\Command\Template\CheckList\RenewCheckListItemsCommand;
use Bitrix\Tasks\V2\Public\Command\CheckList\CollapseCheckListCommand;
use Bitrix\Tasks\V2\Public\Command\CheckList\ExpandCheckListCommand;
use Bitrix\Tasks\V2\Public\Command\Template\CheckList\SaveCheckListCommand;
use Bitrix\Tasks\V2\Public\Provider\CheckListProvider;

class CheckList extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Template.CheckList.get
	 */
	#[CloseSession]
	public function getAction(
		#[Permission\Read]
		Entity\Template $template,
		CheckListProvider $checkListProvider,
	): ?Arrayable
	{
		return $checkListProvider->getByEntity(
			$template->getId(),
			$this->userId,
			Entity\CheckList\Type::Template
		);
	}

	/**
	 * @ajaxAction tasks.V2.Template.CheckList.save
	 */
	public function saveAction(
		#[Permission\CheckList\Save]
		Entity\Template $template,
		CheckListProvider $checkListProvider,
	): ?Entity\CheckList
	{
		$result = (new SaveCheckListCommand(
			template: $template,
			updatedBy: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $checkListProvider->getByEntity(
			$template->getId(),
			$this->userId,
			Entity\CheckList\Type::Template,
		);
	}

	/**
	 * @ajaxAction tasks.V2.Template.CheckList.complete
	 */
	public function completeAction(
		#[Permission\CheckList\Toggle]
		Entity\Template $template,
	): bool
	{
		$result = (new CompleteCheckListItemsCommand(
			ids: array_column($template->checklist, 'id'),
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return false;
		}

		return true;
	}

	/**
	 * @ajaxAction tasks.V2.Template.CheckList.renew
	 */
	public function renewAction(
		#[Permission\CheckList\Toggle]
		Entity\Template $template,
	): bool
	{
		$result = (new RenewCheckListItemsCommand(
			ids: array_column($template->checklist, 'id'),
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return false;
		}

		return true;
	}

	/**
	 * @ajaxAction tasks.V2.Template.CheckList.collapse
	 */
	public function collapseAction(int $templateId, int $checkListId): bool
	{
		if (!TemplateAccessController::can($this->userId, ActionDictionary::ACTION_TEMPLATE_READ, $templateId))
		{
			$this->addErrors([new Error('Access denied')]);

			return false;
		}

		$result = (new CollapseCheckListCommand(
			checkListId: $checkListId,
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return false;
		}

		return true;
	}

	/**
	 * @ajaxAction tasks.V2.Template.CheckList.expand
	 */
	public function expandAction(int $templateId, int $checkListId): bool
	{
		if (!TemplateAccessController::can($this->userId, ActionDictionary::ACTION_TEMPLATE_READ, $templateId))
		{
			$this->addErrors([new Error('Access denied')]);

			return false;
		}

		$result = (new ExpandCheckListCommand(
			checkListId: $checkListId,
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return false;
		}

		return true;
	}
}
