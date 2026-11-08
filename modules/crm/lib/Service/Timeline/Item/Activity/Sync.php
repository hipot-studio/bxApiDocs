<?php

namespace Bitrix\Crm\Service\Timeline\Item\Activity;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Timeline\Context;
use Bitrix\Crm\Service\Timeline\Item\Activity;
use Bitrix\Crm\Service\Timeline\Item\Model;
use Bitrix\Crm\Service\Timeline\Layout\Action\JsEvent;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\ActionBar\ActionBar;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\ActionBar\ActionBarItem;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\ContentBlockFactory;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\GroupBlocks;
use Bitrix\Crm\Service\Timeline\Layout\Body\Logo;
use Bitrix\Crm\Service\Timeline\Layout\Common;
use Bitrix\Crm\Service\Timeline\Layout\Footer\Button;
use Bitrix\Crm\V2\Internal\Integration\Im\SyncChatAccess;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

Container::getInstance()->getLocalization()->loadMessages();

final class Sync extends Activity
{
	private SyncChatAccess $chatAccess;

	public function __construct(Context $context, Model $model, ?SyncChatAccess $chatAccess = null)
	{
		$this->chatAccess = $chatAccess ?? new SyncChatAccess();
		parent::__construct($context, $model);
	}

	protected function getActivityTypeId(): string
	{
		return 'Sync';
	}

	public function getIconCode(): ?string
	{
		return $this->isScheduled()
			? Common\Icon::CALL_INCOMING
			: Common\Icon::CALL_COMPLETED
		;
	}

	public function getLogo(): ?Logo
	{
		return Common\Logo::getInstance(Common\Logo::CALL_INCOMING)->createLogo();
	}

	public function getTitle(): string
	{
		return (string)Loc::getMessage('CRM_TIMELINE_ITEM_SYNC_TITLE');
	}

	public function getContentBlocks(): array
	{
		$result = [];
		$group = new GroupBlocks();

		$subject = (string)($this->getAssociatedEntityModel()?->get('SUBJECT') ?? '');
		if ($subject !== '')
		{
			$subjectBlock = ContentBlockFactory::createTitle($subject);
			$group->addBlock('subject', $subjectBlock);
			$result['subject'] = (clone $subjectBlock)->setScopeMobile();
		}

		$callsActionBar = $this->buildCallsActionBar();
		if ($callsActionBar !== null)
		{
			$group->addBlock('callsActionBar', $callsActionBar);
		}

		if ($group->isFilled())
		{
			$result['syncGroupOfBlocks'] = $group->setScopeWeb();
		}

		return $result;
	}

	public function getButtons(): array
	{
		$buttons = parent::getButtons() ?? [];

		$chatId = $this->getChatId();
		if ($chatId > 0 && $this->chatAccess->canOpen($chatId, $this->getContext()->getUserId()))
		{
			$buttons['openChat'] = (new Button(
				(string)Loc::getMessage('CRM_TIMELINE_ITEM_SYNC_BUTTON_OPEN'),
				$this->isScheduled() ? Button::TYPE_PRIMARY : Button::TYPE_SECONDARY,
			))
				->setAction(
					(new JsEvent('Activity:Sync:OpenChat'))
						->addActionParamString('dialogId', $this->getDialogId($chatId))
				)
				->setScopeWeb()
			;
		}

		return $buttons;
	}

	public function getMenuItems(): array
	{
		$menuItems = parent::getMenuItems();
		unset($menuItems['view']);

		return $menuItems;
	}

	private function buildCallsActionBar(): ?ContentBlock
	{
		$chatId = $this->getChatId();
		if ($chatId <= 0 || !ModuleManager::isModuleInstalled('call'))
		{
			return null;
		}

		$showCallsItem = (new ActionBarItem())
			->setSize(ActionBarItem::SIZE_SM)
			->setDesign(ActionBarItem::DESIGN_OUTLINE)
			->setIsDropdown(true)
			->setText((string)Loc::getMessage('CRM_TIMELINE_ITEM_SYNC_ACTION_BAR_CALLS'))
			->setAction(
				(new JsEvent('Activity:Sync:ShowCalls'))
					->addActionParamInt('activityId', $this->getActivityId())
					->addActionParamString('dialogId', $this->getDialogId($chatId))
			)
		;

		return (new ActionBar())
			->addItem('showCalls', $showCallsItem)
			->setScopeWeb()
		;
	}

	private function getChatId(): int
	{
		return (int)($this->getAssociatedEntityModel()?->get('ASSOCIATED_ENTITY_ID') ?? 0);
	}

	private function getDialogId(int $chatId): string
	{
		return "chat{$chatId}";
	}
}
