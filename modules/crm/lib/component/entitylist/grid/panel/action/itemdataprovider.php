<?php

namespace Bitrix\Crm\Component\EntityList\Grid\Panel\Action;

use Bitrix\Crm\CallList\CallList;
use Bitrix\Crm\Component\EntityList\Grid\Panel\Action\CallList\CreateAndStartCallListAction;
use Bitrix\Crm\Component\EntityList\Grid\Panel\Action\Item\DeleteAction;
use Bitrix\Crm\Component\EntityList\Grid\Panel\Action\Item\EditAction;
use Bitrix\Crm\Component\EntityList\Grid\Panel\Action\Item\Group\WhatsAppMessageChildAction;
use Bitrix\Crm\Component\EntityList\Grid\Panel\Action\Item\ItemGroupAction;
use Bitrix\Crm\Component\EntityList\Grid\Panel\Action\Item\MyCompanyItemGroupAction;
use Bitrix\Crm\Component\EntityList\Grid\Panel\Action\Item\RecurringItemGroupAction;
use Bitrix\Crm\Component\EntityList\Grid\Panel\Action\Item\UnbindClientAction;
use Bitrix\Crm\Component\EntityList\Grid\Settings\ItemSettings;
use Bitrix\Crm\Service\Context;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Grid\Panel\Action\DataProvider;
use Bitrix\Main\Grid\Panel\Action\ForAllCheckboxAction;
use Bitrix\Main\ModuleManager;

/**
 * @method ItemSettings getSettings()
 */
final class ItemDataProvider extends DataProvider
{
	public function __construct(
		private Factory $factory,
		private UserPermissions $userPermissions,
		private Context $context,
		ItemSettings $settings,
	)
	{
		parent::__construct($settings);

		if ($this->context->getUserId() !== $this->userPermissions->getUserId())
		{
			throw new ArgumentException('Context and UserPermissions should mention the same user');
		}
	}

	public function prepareActions(): array
	{
		$actions = [];

		// the client tab offers unbinding even to those who can change nothing in the items themselves
		if ($this->getSettings()->getClientTab()?->canUpdateOwner === true)
		{
			$actions[] = new UnbindClientAction($this->getGridId());
		}

		$canDelete = is_null($this->getCategoryId())
			? $this->userPermissions->entityType()->canDeleteItems($this->factory->getEntityTypeId())
			: $this->userPermissions->entityType()->canDeleteItemsInCategory($this->factory->getEntityTypeId(), $this->getCategoryId())
		;

		// the client tab offers deletion in the group actions, its separate button is "Unbind"
		if ($canDelete && !$this->isClientTab())
		{
			$actions[] = new DeleteAction($this->factory->getEntityTypeId(), $this->getGridId());
		}

		if (!$this->getSettings()->isMyCompany())
		{
			$canUpdate = is_null($this->getCategoryId())
				? $this->userPermissions->entityType()->canUpdateItems($this->factory->getEntityTypeId())
				: $this->userPermissions->entityType()->canUpdateItemsInCategory($this->factory->getEntityTypeId(), $this->getCategoryId())
			;

			if ($canUpdate)
			{
				$actions[] = new EditAction(
					$this->factory,
					$this->context,
					$this->getGridId(),
					$this->getCategoryId(),
					$this->getSettings()->getEditableFieldsWhitelist(),
					$this->getSettings()->getColumnNameToEditableFieldNameMap(),
				);
			}

			if (
				!$this->getSettings()->isRecurring()
				&& !$this->isClientTab()
				&& ModuleManager::isModuleInstalled('voximplant')
				&& CallList::isEntityTypeSupported($this->factory->getEntityTypeId())
			)
			{
				$actions[] = new CreateAndStartCallListAction(
					$this->factory->getEntityTypeId(),
					$this->getGridId(),
				);
			}
		}

		if (
			!empty($actions)
			|| $canDelete
			|| WhatsAppMessageChildAction::isAvailable($this->factory->getEntityTypeId(), $this->getSettings())
		)
		{
			if ($this->getSettings()->isMyCompany())
			{
				$actions[] = new MyCompanyItemGroupAction(
					$this->factory,
					$this->userPermissions,
					$this->getSettings(),
				);
			}
			elseif ($this->getSettings()->isRecurring())
			{
				$actions[] = new RecurringItemGroupAction(
					$this->factory,
					$this->userPermissions,
					$this->getSettings(),
				);
			}
			else
			{
				$actions[] = new ItemGroupAction(
					$this->factory,
					$this->userPermissions,
					$this->getSettings(),
				);
			}

			if (!$this->isClientTab())
			{
				$actions[] = new ForAllCheckboxAction();
			}
		}

		return $actions;
	}

	private function isClientTab(): bool
	{
		return $this->getSettings()->getClientTab() !== null;
	}

	private function getCategoryId(): ?int
	{
		return $this->getSettings()->getCategoryId();
	}

	private function getGridId(): string
	{
		return $this->getSettings()->getID();
	}
}
