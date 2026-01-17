<?php

namespace Bitrix\Crm\Service\Timeline\Item\Activity;

use Bitrix\Crm\Activity\Provider\ProviderManager;
use Bitrix\Crm\Badge\Model\BadgeTable;
use Bitrix\Crm\Integration\AI\Model\QueueTable;
use Bitrix\Crm\Integration\AI\Operation\SummarizeCallTranscription;
use Bitrix\Crm\Integration\OpenLineManager;
use Bitrix\Crm\Service\Timeline\Item\Activity;
use Bitrix\Crm\Service\Timeline\Item\AI\CopilotButton\BaseButton;
use Bitrix\Crm\Service\Timeline\Item\Interfaces\HasCopilot;
use Bitrix\Crm\Service\Timeline\Item\Mixin\CopilotHelper;
use Bitrix\Crm\Service\Timeline\Layout;
use Bitrix\Crm\Service\Timeline\Layout\Action;
use Bitrix\Crm\Service\Timeline\Layout\Action\Animation;
use Bitrix\Crm\Service\Timeline\Layout\Action\JsEvent;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock;
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
use Bitrix\Main\Localization\Loc;
use CCrmActivity;
use CCrmOwnerType;

final class OpenLine extends Activity implements HasCopilot
{
	use CopilotHelper;

	private const SUMMARIZE_TRANSCRIPTION_LIMIT = 10;
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
		if ($lineName)
		{
			$result['lineTitle'] = (new ContentBlockWithTitle())
				->setTitle(Loc::getMessage('CRM_TIMELINE_BLOCK_TITLE_NAME'))
				->setContentBlock((new Link())->setValue($lineName)->setAction($this->getOpenChatAction()))
				->setInline()
			;
		}

		$clientBlock = $this->buildClientBlock(Client::BLOCK_WITH_FIXED_TITLE);
		if (isset($clientBlock))
		{
			$result['client'] = $clientBlock;
		}

		$sourceList = [];
		$providerId = $this->getAssociatedEntityModel()?->get('PROVIDER_ID');
		if ($providerId && $provider = CCrmActivity::GetProviderById($providerId))
		{
			$sourceList = $provider::getResultSources();
		}

		if (!empty($sourceList))
		{
			$connectorType = OpenLineManager::getLineConnectorType($userCode);
			$channelName = $sourceList[$connectorType] ?? $sourceList['livechat'];
			$result['chatTitle'] = (new ContentBlockWithTitle())
				->setTitle(Loc::getMessage('CRM_TIMELINE_BLOCK_TITLE_CHANNEL'))
				->setContentBlock(ContentBlockFactory::createTitle($channelName)->setColor(Text::COLOR_BASE_90))
				->setInline()
			;
		}

		if (empty($result))
		{
			$subject = (string)($this->getAssociatedEntityModel()?->get('SUBJECT') ?? '');
			if (!empty($subject))
			{
				$result['subject'] = ContentBlockFactory::createTextOrLink(
					$subject,
					$this->getOpenChatAction()
				);
			}
		}

		$chatActivityBlock = $this->buildChatActivityBlock();
		if (isset($chatActivityBlock))
		{
			$result['chatActivityBlock'] = $chatActivityBlock;
		}

		$clientMarkBlock = $this->buildClientMarkBlock();
		if (isset($clientMarkBlock))
		{
			$result['clientMark'] = $clientMarkBlock;
		}

		$copilotSummaryBlock = $this->buildCopilotSummaryBlock();
		if (isset($copilotSummaryBlock))
		{
			$result['copilotSummaryBlock'] = $copilotSummaryBlock->setScopeWeb();
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

		return [
			'openChat' => (
				new Button(
					Loc::getMessage($this->isScheduled()
						? 'CRM_TIMELINE_BUTTON_OPEN_CHAT_MSGVER_1'
						: 'CRM_TIMELINE_BUTTON_SEE_CHAT')
					,
					$this->isScheduled() ? Button::TYPE_PRIMARY : Button::TYPE_SECONDARY
				)
			)->setAction($openChatAction),
			'aiButton' => $this->getCopilotButton(),
		];
	}

	public function getMenuItems(): array
	{
		$items = parent::getMenuItems();
		unset($items['view']);

		return $items;
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

		$aiTags = $this->getAiTags(
			$this->getContext()->getIdentifier()->getEntityTypeId(),
			$this->getContext()->getIdentifier()->getEntityId(),
			$this->getActivityId()
		);

		return array_merge($tags, $aiTags);
	}

	public function needShowNotes(): bool
	{
		return true;
	}

	public function getCopilotButton(): ?BaseButton
	{
		$isButtonVisible = $this->isCopilotScope()
			&& $this->hasUpdatePermission()
			&& $this->isItemHashValid($this->getActivityId(), $this->getContext())
		;

		if (!$isButtonVisible)
		{
			return null;
		}

		return $this->createCopilotButton();
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

	private function buildChatActivityBlock(): ?ContentBlock
	{
		$userCode = $this->getOpenLineUserCode();
		/** @var \Bitrix\Im\V2\Message $message */
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

		$vote = (int)$sessionData['VOTE'];
		if ($vote <= 0)
		{
			return null;
		}

		$clientMark = $this->mapClientMark($vote);
		if (!isset($clientMark))
		{
			return null;
		}

		return (new ClientMark())
			->setMark($clientMark)
			->setText(Loc::getMessage(sprintf('CRM_TIMELINE_BLOCK_CLIENT_MARK_%s', mb_strtoupper($clientMark))))
		;
	}

	private function buildCopilotSummaryBlock(): ?ContentBlock
	{
		if (!$this->isCopilotScope())
		{
			return null;
		}

		$activityId = $this->getActivityId();
		$list = $this->getSummarizeTranscriptionList($activityId);
		if (empty($list))
		{
			return null;
		}

		$block = new LineOfTextBlocks();

		$blockTitle = ContentBlockFactory::createTitle(Loc::getMessage('CRM_TIMELINE_BLOCK_TITLE_VIEW_COPILOT_SUMMARY'))
			->setColor(Text::COLOR_BASE_60)
		;
		$block->addContentBlock('copilotSummaryBlockTitle', $blockTitle);

		$viewLink = (new Link())
			->setValue(Loc::getMessage('CRM_TIMELINE_BUTTON_SEE_CHAT'))
			->setAction((new JsEvent('Openline:ShowCopilotSummary'))
				->addActionParamInt('activityId', $activityId)
				->addActionParamInt('ownerTypeId', $this->getContext()->getEntityTypeId())
				->addActionParamInt('ownerId', $this->getContext()->getEntityId())
				->addActionParamArray('summarizeTranscriptionList', $list)
			)
			->setDecoration(Text::DECORATION_DASHED)
		;
		if (count($list) > 1)
		{
			$viewLink->setIcon('chevron');
		}

		$itemId = $this->model->getId();
		$block->addContentBlock("copilotSummaryBlockLink_$itemId", $viewLink);

		return $block;
	}

	private function getSessionData(): array
	{
		$sessionId = $this->getModel()->getAssociatedEntityModel()?->get('ASSOCIATED_ENTITY_ID') ?? 0;

		return $sessionId > 0
			? OpenLineManager::getSessionData($sessionId)
			: []
		;
	}

	private function mapClientMark(int $vote): ?string
	{
		if ($vote > 3)
		{
			return ClientMark::POSITIVE;
		}

		if ($vote === 3)
		{
			return ClientMark::NEUTRAL;
		}

		if ($vote > 0)
		{
			return ClientMark::NEGATIVE;
		}

		return null;
	}

	private function getOpenLineUserCode(): ?string
	{
		return $this->getAssociatedEntityModel()?->get('PROVIDER_PARAMS')['USER_CODE'];
	}

	private function getSummarizeTranscriptionList(int $activityId): array
	{
		$rawData = QueueTable::query()
			->setSelect(['ID', 'FINISHED_TIME'])
			->where('ENTITY_TYPE_ID', CCrmOwnerType::Activity)
			->where('ENTITY_ID', $activityId)
			->where('TYPE_ID', SummarizeCallTranscription::TYPE_ID)
			->where('EXECUTION_STATUS', QueueTable::EXECUTION_STATUS_SUCCESS)
			->setOrder(['FINISHED_TIME' => 'DESC'])
			->setLimit(self::SUMMARIZE_TRANSCRIPTION_LIMIT)
			->fetchCollection()
			->getAll()
		;

		$result = [];
		foreach ($rawData as $item)
		{
			$result[$item->getId()] = $item->getFinishedTime()?->getTimestamp();
		}

		return $result;
	}
}
