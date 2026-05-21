<?php

namespace Bitrix\Crm\Service\Timeline\Item\Activity;

use Bitrix\Crm\Activity\Provider\ProviderManager;
use Bitrix\Crm\Badge\Model\BadgeTable;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Integration\AI\Operation\SummarizeCallTranscription;
use Bitrix\Crm\Integration\OpenLineManager;
use Bitrix\Crm\Service\Timeline\Item\AIActivity;
use Bitrix\Crm\Service\Timeline\Layout;
use Bitrix\Crm\Service\Timeline\Layout\Action;
use Bitrix\Crm\Service\Timeline\Layout\Action\Animation;
use Bitrix\Crm\Service\Timeline\Layout\Action\JsEvent;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\ActionBar\ActionBarItem;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\Client;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\ClientMark;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\ContentBlockFactory;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\ContentBlockWithTitle;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\LineOfTextBlocks;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\Link;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\Text;
use Bitrix\Crm\Service\Timeline\Layout\Common\Icon;
use Bitrix\Crm\Service\Timeline\Layout\Footer\Button;
use Bitrix\Crm\Service\Timeline\Layout\Header\ChangeStreamButton;
use Bitrix\Crm\Service\Timeline\Layout\Header\Tag;
use Bitrix\Im\V2\Message;
use Bitrix\Main\Localization\Loc;
use CCrmActivity;

final class OpenLine extends AIActivity
{
	private const DEFAULT_ADDITIONAL_ICON_CODE = 'livechat';

	protected function getActivityTypeId(): string
	{
		return 'OpenLine';
	}

	public function getIconCode(): ?string
	{
		return Icon::IM;
	}

	public function getTitle(): string
	{
		return Loc::getMessage($this->isScheduled()
			? 'CRM_TIMELINE_TITLE_OPEN_LINE_MSGVER_1'
			: 'CRM_TIMELINE_TITLE_OPEN_LINE_DONE'
		);
	}

	public function getTitleAction(): ?Action
	{
		return $this->getOpenChatAction();
	}

	public function getLogo(): ?Layout\Body\Logo
	{
		$additionalIconCode = self::DEFAULT_ADDITIONAL_ICON_CODE;
		$userCode = $this->getOpenLineUserCode();
		if (isset($userCode))
		{
			$additionalIconCode = OpenLineManager::getLineConnectorType($userCode) ?? self::DEFAULT_ADDITIONAL_ICON_CODE;
		}

		return Layout\Common\Logo::getInstance(Layout\Common\Logo::OPENLINE)
			->createLogo()
			?->setAdditionalIconCode($additionalIconCode)
			?->setAction($this->getOpenChatAction())
		;
	}

	public function getContentBlocks(): array
	{
		$result = [];

		$userCode = $this->getOpenLineUserCode();
		$lineName = OpenLineManager::getLineTitle($userCode);
		$channelName = $this->getOpenLineChannelName($userCode);
		$subject = (string)($this->getAssociatedEntityModel()?->get('SUBJECT') ?? '');

		$clientBlock = $this->buildClientBlock(Client::BLOCK_WITH_COMMUNICATION_CONTROL);
		$lineTitleBlock = $lineName
			? (new ContentBlockWithTitle())
				->setTitle(Loc::getMessage('CRM_TIMELINE_BLOCK_TITLE_NAME'))
				->setContentBlock((new Link())->setValue($lineName)->setAction($this->getOpenChatAction()))
				->setFixedWidth(false)
				->setInline()
			: null
		;
		$chatTitleBlock = $channelName
			? (new ContentBlockWithTitle())
				->setTitle(Loc::getMessage('CRM_TIMELINE_BLOCK_TITLE_CHANNEL'))
				->setContentBlock(ContentBlockFactory::createTitle($channelName)->setColor(Text::COLOR_BASE_90))
				->setFixedWidth(false)
				->setInline()
			: null
		;
		$subjectBlock = !isset($clientBlock, $lineTitleBlock, $chatTitleBlock) && !empty($subject)
			? ContentBlockFactory::createTextOrLink($subject, $this->getOpenChatAction())
			: null
		;
		$chatActivityBlock = $this->buildChatActivityBlock();
		$clientMarkBlock = $this->buildClientMarkBlock();

		if ($clientBlock)
		{
			$result['client'] = $clientBlock;
		}
		if ($lineTitleBlock)
		{
			$result['lineTitle'] = $lineTitleBlock->setScopeMobile();
		}
		if ($chatTitleBlock)
		{
			$result['chatTitle'] = $chatTitleBlock->setScopeMobile();
		}
		if ($subjectBlock)
		{
			$result['subject'] = $subjectBlock;
		}
		if (isset($chatActivityBlock))
		{
			$result['chatActivityBlock'] = $chatActivityBlock->setScopeMobile();
		}
		if (isset($clientMarkBlock))
		{
			$result['clientMark'] = $clientMarkBlock->setScopeMobile();
		}

		// group blocks for web
		$groupBlock = $this->buildGroupBlocks($result);
		if ($groupBlock->isFilled())
		{
			$result['chatGroupOfBlocks'] = $groupBlock->setScopeWeb();

		}
		$actionBarBlock = $this->buildAiActionBar();
		if ($actionBarBlock->isFilled())
		{
			$result['aiActionBar'] = $actionBarBlock->setScopeWeb();
		}

		return $result;
	}

	public function getButtons(): array
	{
		$openChatAction = $this->getOpenChatAction();
		if (!$openChatAction)
		{
			return [];
		}

		return array_merge(
			[
				'openChat' => (
				new Button(
					Loc::getMessage($this->isScheduled()
						? 'CRM_TIMELINE_BUTTON_OPEN_CHAT_MSGVER_1'
						: 'CRM_TIMELINE_BUTTON_SEE_CHAT')
					,
					$this->isScheduled() ? Button::TYPE_PRIMARY : Button::TYPE_SECONDARY
				)
				)->setAction($openChatAction),
			],
			$this->getAIButtons(),
		);
	}

	public function getMenuItems(): array
	{
		$items = parent::getMenuItems();
		unset($items['view']);

		return array_merge($items, $this->getAIMenuItems());
	}

	public function getTags(): ?array
	{
		$tags = [];

		$userCode = $this->getOpenLineUserCode();
		$responsibleId = $this->getAssociatedEntityModel()?->get('RESPONSIBLE_ID');

		// the tag will not be removed until the responsible user reads all messages
		if (
			$this->isScheduled()
			&& OpenLineManager::getChatUnReadMessagesCount($userCode, $responsibleId) > 0
		)
		{
			$tags['notReadChat'] = new Tag(
				Loc::getMessage('CRM_TIMELINE_TAG_CHAT_NOT_READ'),
				Tag::TYPE_WARNING
			);
		}
		elseif (BadgeTable::isActivityHasBadge($this->getActivityId()))
		{
			$activity = CCrmActivity::GetByID($this->getActivityId(), false);
			if (is_array($activity))
			{
				ProviderManager::syncBadgesOnActivityUpdate($this->getActivityId(), $activity);
			}
		}

		return array_merge($tags, $this->getAITags());
	}

	protected function getScenarios(): array
	{
		if ($this->isAIScope() && $this->getAIService()->isFieldsFillingWrong())
		{
			return [
				Scenario::CONFIRM_FIELDS_SCENARIO,
			];
		}

		return [
			Scenario::FILL_FIELDS_SCENARIO,
		];
	}

	protected function canShowAIActions(): bool
	{
		return true; // show AI actions, but it disabled when no valid data in chat
	}

	protected function createViewCopilotSummaryItem(array $list): ?ActionBarItem
	{
		$activityId = $this->getActivityId();
		$languageTitle = $this->getAIService()->getAILanguage(SummarizeCallTranscription::TYPE_ID);
		$barItemAction = (new JsEvent('Openline:ShowCopilotSummary'))
			->addActionParamInt('activityId', $activityId)
			->addActionParamInt('ownerTypeId', $this->getContext()->getEntityTypeId())
			->addActionParamInt('ownerId', $this->getContext()->getEntityId())
			->addActionParamString('languageTitle', $languageTitle)
			->addActionParamArray('summarizeTranscriptionList', $list)
		;
		$barItem = (new ActionBarItem())
			->setSize(ActionBarItem::SIZE_SM)
			->setDesign(ActionBarItem::DESIGN_COPILOT)
			->setAction($barItemAction)
			->setText(
				Loc::getMessage(
					'CRM_TIMELINE_BLOCK_OPEN_LINE_ACTION_BAR_COPILOT_SUMMARY',
					['#COPILOT_NAME#' => AIManager::getCopilotName()]
				)
			)
		;
		if (count($list) > 1)
		{
			$barItem->setIsDropdown(true);
		}

		return $barItem;
	}

	protected function getOpenChatAction(): ?Action
	{
		$communication = $this->getAssociatedEntityModel()?->get('COMMUNICATION') ?? [];
		$dialogId = $communication['VALUE'] ?? null;
		if (!$dialogId || $communication['TYPE'] !== 'IM')
		{
			return null;
		}

		return (new JsEvent('Openline:OpenChat'))
			->addActionParamString('dialogId', $dialogId)
		;
	}

	protected function getCompleteButton(): ?ChangeStreamButton
	{
		if (!$this->isScheduled())
		{
			return null;
		}

		$completeAction =  (new JsEvent('Openline:Complete'))
			->addActionParamInt('activityId', $this->getActivityId())
			->addActionParamInt('ownerTypeId', $this->getContext()->getEntityTypeId())
			->addActionParamInt('ownerId', $this->getContext()->getEntityId())
			->addActionParamString('ajaxAction', $this->getCompleteAction()->getAction())
			->setAnimation(Animation::disableItem()->setForever())
		;

		return (new ChangeStreamButton())
			->setTypeComplete()
			->setDisableIfReadonly()
			->setAction($completeAction)
		;
	}

	protected function canMoveTo(): bool
	{
		return $this->isScheduled();
	}

	// region Build content blocks
	private function buildChatActivityBlock(): ?ContentBlock
	{
		$userCode = $this->getOpenLineUserCode();
		/** @var Message $message */
		$message = OpenLineManager::getLastMessage($userCode);
		if ($message === null)
		{
			return null;
		}

		$isExtranetUser = $message->getAuthor()?->isExtranet();
		$text = Loc::getMessage(
			$isExtranetUser ? 'CRM_TIMELINE_BLOCK_TITLE_CHAT_ACTIVITY_CLIENT' : 'CRM_TIMELINE_BLOCK_TITLE_CHAT_ACTIVITY_MANAGER',
			[
				'#DATETIME#' => OpenLineManager::getMessageCreatedDate($message),
			],
		) ?? '';

		return (new ContentBlockWithTitle())
			->setTitle(Loc::getMessage('CRM_TIMELINE_BLOCK_TITLE_CHAT_ACTIVITY'))
			->setContentBlock(ContentBlockFactory::createTitle($text)->setColor(Text::COLOR_BASE_90))
			->setFixedWidth(false)
			->setInline()
		;
	}

	private function buildClientMarkBlock(): ?ContentBlock
	{
		$sessionData = $this->getSessionData();
		if (empty($sessionData))
		{
			return null;
		}

		$clientMark = ClientMark::getByChatVote((int)$sessionData['VOTE']);
		if (!isset($clientMark))
		{
			return null;
		}

		return (new ClientMark())
			->setMark($clientMark)
			->setText(Loc::getMessage(sprintf('CRM_TIMELINE_BLOCK_CLIENT_MARK_%s', mb_strtoupper($clientMark))))
		;
	}

	private function buildGroupBlocks(array $blocks): ContentBlock\GroupBlocks
	{
		$groupedBlock = new ContentBlock\GroupBlocks();

		if (isset($blocks['lineTitle'], $blocks['chatTitle']))
		{
			$chatTitleBlock = ContentBlockFactory::createTitle(
				Loc::getMessage(
					'CRM_TIMELINE_BLOCK_OPEN_LINE_CHAT_LINE_DELIMITER',
					['#CHAT_TITLE#' => $blocks['chatTitle']->getContentBlock()->getValue() ?? '']
				)
			)->setColor(Text::COLOR_BASE_90);
			$lineAndChatBlock = (new LineOfTextBlocks())
				->addContentBlock('lineTitle', $blocks['lineTitle']->getContentBlock())
				->addContentBlock('chatTitle',  $chatTitleBlock)
			;
			$groupedBlock->addBlock('lineAndChat', $lineAndChatBlock);
		}

		if (isset($blocks['chatActivityBlock']))
		{
			$groupedBlock->addBlock('chatActivityBlock', $blocks['chatActivityBlock']->getContentBlock());
		}

		if (isset($blocks['clientMark']))
		{
			$clientMarkBlock = (clone $blocks['clientMark'])->setScopeWeb();
			$groupedBlock->addBlock('clientMark', $clientMarkBlock);
		}

		return $groupedBlock;
	}
	// endregion

	// region Internal utils
	private function getSessionData(): array
	{
		$sessionId = (int)($this->getModel()->getAssociatedEntityModel()?->get('ASSOCIATED_ENTITY_ID') ?? 0);

		return $sessionId > 0
			? OpenLineManager::getSessionData($sessionId)
			: []
		;
	}

	private function getOpenLineUserCode(): ?string
	{
		return $this->getAssociatedEntityModel()?->get('PROVIDER_PARAMS')['USER_CODE'];
	}

	private function getOpenLineChannelName(?string $userCode): ?string
	{
		$providerId = $this->getAssociatedEntityModel()?->get('PROVIDER_ID');
		if (!$providerId)
		{
			return null;
		}

		$connectorType = OpenLineManager::getLineConnectorType($userCode);
		if (!$connectorType)
		{
			return null;
		}

		$provider = CCrmActivity::GetProviderById($providerId);
		$sourceList = $provider ? $provider::getResultSources() : [];
		if (empty($sourceList))
		{
			return null;
		}

		return $sourceList[$connectorType] ?? $sourceList[self::DEFAULT_ADDITIONAL_ICON_CODE];
	}
	// endregion
}
