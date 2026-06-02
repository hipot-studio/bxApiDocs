<?php

namespace Bitrix\Crm\Service\Timeline\Item\Activity;

use Bitrix\Crm\Copilot\AiQualityAssessment\Controller\AiQualityAssessmentController;
use Bitrix\Crm\Format\Duration;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Dto\Scoring\ScoreCallPayload;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Integration\AI\Operation\ScoreCall;
use Bitrix\Crm\Integration\AI\Operation\SummarizeCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\TranscribeCallRecording;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\Integration\VoxImplantManager;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Timeline\Item\AIActivity;
use Bitrix\Crm\Service\Timeline\Item\Payload;
use Bitrix\Crm\Service\Timeline\Layout;
use Bitrix\Crm\Service\Timeline\Layout\Action\JsEvent;
use Bitrix\Crm\Service\Timeline\Layout\Action\Redirect;
use Bitrix\Crm\Service\Timeline\Layout\Action\ShowMenu;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\ActionBar\ActionBarItem;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\Audio;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\Client;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\ClientMark;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\ContentBlockFactory;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\ContentBlockWithTitle;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\EditableDescription;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\LineOfTextBlocks;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\Text;
use Bitrix\Crm\Service\Timeline\Layout\Common\Icon;
use Bitrix\Crm\Service\Timeline\Layout\Footer\Button;
use Bitrix\Crm\Service\Timeline\Layout\Footer\IconButton;
use Bitrix\Crm\Service\Timeline\Layout\Header\Tag;
use Bitrix\Crm\Service\Timeline\Layout\Menu;
use Bitrix\Crm\Service\Timeline\Layout\Menu\MenuItemFactory;
use Bitrix\Crm\Tour\CopilotInCall;
use Bitrix\Crm\Tour\CopilotRunAutomatically;
use Bitrix\Crm\Tour\CopilotRunManually;
use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\PhoneNumber;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Uri;
use CCrmActivityDirection;
use CCrmDateTimeHelper;
use CCrmFieldMulti;
use CCrmOwnerType;
use CFile;

class Call extends AIActivity
{
	private const BLOCK_DELIMITER = '&bull;';

	final protected function getActivityTypeId(): string
	{
		return 'Call';
	}

	final public function getIconCode(): ?string
	{
		switch ($this->fetchDirection())
		{
			case CCrmActivityDirection::Incoming:
				if ($this->isMissedCall())
				{
					return Icon::CALL_INCOMING_MISSED;
				}

				return $this->isScheduled() ? Icon::CALL_INCOMING : Icon::CALL_COMPLETED;
			case CCrmActivityDirection::Outgoing:
				return Icon::CALL_OUTCOMING;
		}

		return Icon::CALL;
	}

	final public function getTitle(): string
	{
		switch ($this->fetchDirection())
		{
			case CCrmActivityDirection::Incoming:
				if ($this->isMissedCall())
				{
					return Loc::getMessage(
						$this->isScheduled()
							? 'CRM_TIMELINE_TITLE_CALL_MISSED'
							: 'CRM_TIMELINE_TITLE_CALL_INCOMING_DONE'
					);
				}

				// set call end time to correct title in header
				if ($this->isScheduled())
				{
					$userTime = (string)$this->getAssociatedEntityModel()?->get('END_TIME');
					if (!empty($userTime) && !CCrmDateTimeHelper::IsMaxDatabaseDate($userTime))
					{
						$this->getModel()->setDate(DateTime::createFromUserTime($userTime));
					}
				}

				$scheduledCode = $this->isPlanned()
					? 'CRM_TIMELINE_TITLE_CALL_INCOMING_PLAN'
					: 'CRM_TIMELINE_TITLE_CALL_INCOMING';

				return Loc::getMessage($this->isScheduled() ? $scheduledCode : 'CRM_TIMELINE_TITLE_CALL_INCOMING_DONE');
			case CCrmActivityDirection::Outgoing:
				return Loc::getMessage(
					$this->isPlanned()
						? 'CRM_TIMELINE_TITLE_CALL_OUTGOING_PLAN'
						: 'CRM_TIMELINE_TITLE_CALL_OUTGOING'
				);
		}

		return Loc::getMessage('CRM_TIMELINE_CALL_TITLE_DEFAULT');
	}

	final public function getLogo(): ?Layout\Body\Logo
	{
		$recordUrls = array_unique(array_column($this->fetchAudioRecordList(), 'VIEW_URL'));
		$hasAudio = !empty($recordUrls) && isset($recordUrls[0]);
		$direction = $this->fetchDirection();
		$logoType = $hasAudio
			? Layout\Common\Logo::CALL_PLAY_RECORD
			: $this->getLogoTypeByDirection($direction)
		;
		$logo = Layout\Common\Logo::getInstance($logoType)->createLogo();
		if (!$logo)
		{
			return null;
		}

		if ($hasAudio)
		{
			$logo->setAction((new JsEvent('Call:ChangePlayerState'))
				->addActionParamInt('recordId', $this->getAssociatedEntityModel()?->get('ID'))
				->addActionParamString('recordUri', $recordUrls[0])
			);
		}

		return $this->applyLogoModifications($logo, $direction, $hasAudio);
	}

	final public function getContentBlocks(): array
	{
		$result = [];

		$recordUrls = array_unique(array_column($this->fetchAudioRecordList(), 'VIEW_URL'));

		$clientBlockOptions = Client::BLOCK_WITH_FORMATTED_VALUE | Client::BLOCK_WITH_COMMUNICATION_CONTROL;
		$clientBlock = $this->buildClientBlock($clientBlockOptions);
		$responsibleUserBlock = $this->buildResponsibleUserBlock();
		$deadlineBlock = $this->buildDeadlineBlock();
		$callDateTimeBlock = $this->buildCallDateTimeBlock();
		$subjectBlock = $this->buildSubjectBlock();
		$additionalInfoBlock = $this->buildAdditionalInfoBlock();
		$clientMarkBlock = $this->buildClientMarkBlock();
		$descriptionBlock = $this->buildDescriptionBlock();
		$commentBlock = $this->buildCommentBlock();

		if (isset($clientBlock))
		{
			$result['client'] = $clientBlock;
		}
		if (isset($responsibleUserBlock))
		{
			$result['responsibleUser'] = $responsibleUserBlock->setScopeMobile();
		}
		if (isset($deadlineBlock))
		{
			$result['deadline'] = $deadlineBlock; // for planned calls (old activity)
		}
		if (isset($subjectBlock))
		{
			$result['subject'] = $subjectBlock; // for planned calls (old activity)
		}

		if (!empty($recordUrls))
		{
			if (isset($callDateTimeBlock))
			{
				$result['callDateTime'] = $callDateTimeBlock->setScopeMobile();
			}
			if (isset($additionalInfoBlock))
			{
				$result['callInformation'] = $additionalInfoBlock->setScopeMobile();
			}

			$audio = (new Audio())
				->setId($this->getAssociatedEntityModel()?->get('ID'))
				->setSource($recordUrls[0]) // show first audio record
				->setTranscriptionState($this->fetchTranscriptionState())
			;
			if (isset($clientBlock))
			{
				$communication = $this->getAssociatedEntityModel()?->get('COMMUNICATION') ?? [];
				$title = (new Client($communication, $clientBlockOptions))->getName();
				if (!empty($title))
				{
					$audio->setTitle($title);
				}

				if (
					isset($communication['ENTITY_TYPE_ID'])
					&& $communication['ENTITY_TYPE_ID'] === CCrmOwnerType::Contact
				)
				{
					$photo = (int)(Container::getInstance()
						->getContactBroker()
						->getById($communication['ENTITY_ID'])['PHOTO'] ?? 0)
					;
					if ($photo > 0)
					{
						$photoUrl = CFile::ResizeImageGet($photo, ["width" => 2000, "height" => 2000], BX_RESIZE_IMAGE_PROPORTIONAL, false, false, true);
						$audio->setImageUrl($photoUrl['src']);
					}
				}
			}

			$result['audio'] = $audio->setScopeMobile();
		}

		if (isset($descriptionBlock))
		{
			$result['description'] = $descriptionBlock;
		}

		if (isset($commentBlock))
		{
			$result['comment'] = $commentBlock;
		}

		if (isset($clientMarkBlock))
		{
			$result['clientMark'] = $clientMarkBlock->setScopeMobile();
		}

		// group blocks for web
		$groupBlock = $this->buildGroupBlocks($result);
		if ($groupBlock->isFilled())
		{
			$result['callGroupOfBlocks'] = $groupBlock->setScopeWeb();
		}
		$actionBarBlock = $this->buildAiActionBar();
		if ($actionBarBlock->isFilled())
		{
			$result['aiActionBar'] = $actionBarBlock->setScopeWeb();
		}

		return $result;
	}

	final public function getButtons(): array
	{
		$callButton = $this->getCallButton();
		$scheduleButton = $this->getScheduleButton('Call:Schedule', '', Button::TYPE_PRIMARY);
		$copilotButtons = $this->getAIButtons();

		$buttons = [];
		switch ($this->fetchDirection())
		{
			case CCrmActivityDirection::Incoming:
				if ($this->isMissedCall())
				{
					$buttons['callButton'] = $callButton;
					if ($this->isScheduled())
					{
						$buttons['scheduleButton'] = $scheduleButton;
					}

					return $buttons;
				}

				if ($this->isScheduled())
				{
					return $this->isPlanned()
						? [
							'callButton' => $callButton,
						]
						: array_merge(['scheduleButton' => $scheduleButton], $copilotButtons);
				}

				return array_merge(['callButton' => $callButton], $copilotButtons);
			case CCrmActivityDirection::Outgoing:
				return array_merge(['callButton' => $callButton], $copilotButtons);
		}

		return [];
	}

	final public function getAdditionalIconButton(): ?IconButton
	{
		$callInfo = $this->fetchInfo();
		if ($this->isTranscribed($callInfo))
		{
			return (new IconButton('script', Loc::getMessage('CRM_TIMELINE_BUTTON_TIP_TRANSCRIPT')))
				->setScopeWeb()
				->setAction((new JsEvent('Call:OpenTranscript'))
					->addActionParamString('callId', $callInfo['CALL_ID']))
			;
		}

		return null;
	}

	final public function getMenuItems(): array
	{
		$items = parent::getMenuItems();

		if ($this->isPlanned())
		{
			if (isset($items['edit']))
			{
				$items['edit']->setScopeWeb();
			}

			if (isset($items['view']))
			{
				$items['view']->setScopeWeb();
			}
		}
		else
		{
			unset($items['edit'], $items['view']);
		}

		$records = $this->fetchAudioRecordList();
		$isSingleRecord = (count($records) === 1);
		if (!empty($records))
		{
			foreach ($records as $index => $record)
			{
				$downloadItem = MenuItemFactory::createDownloadFileMenuItem()
					->setAction(
						(new JsEvent('Call:DownloadRecord'))
							->addActionParamString('url', $record['VIEW_URL'])
					)
				;

				if (!$isSingleRecord)
				{
					$downloadItem->setSubtitle($record['NAME']);
				}

				$items["downloadFile_$index"] = $downloadItem;
			}
		}

		$aiMenuItems = (!$this->isAIScope() || $this->isMissedCall())
			? []
			: $this->getAIMenuItems()
		;

		return array_merge($items, $aiMenuItems);
	}

	final public function getTags(): ?array
	{
		$tags = [];

		if ($this->isMissedCall())
		{
			$tags['missedCall'] = new Tag(
				Loc::getMessage('CRM_TIMELINE_TAG_CALL_MISSED'),
				Tag::TYPE_FAILURE
			);
		}

		$callInfo = $this->fetchInfo();
		if ($this->isTranscribed($callInfo) && $callInfo['TRANSCRIPT_PENDING'])
		{
			$tags['transcriptPending'] = new Tag(
				Loc::getMessage('CRM_TIMELINE_TAG_TRANSCRIPT_PENDING'),
				Tag::TYPE_WARNING
			);
		}

		return array_merge($tags, $this->getAITags());
	}

	final public function getPayload(): ?Payload
	{
		if (!$this->isAIScope() || !$this->canShowAIActions())
		{
			return null;
		}

		$activityId = $this->getActivityId();
		$isWelcomeTourEnabled = (CopilotInCall::getInstance())
			->setEntityTypeId($this->getContext()->getIdentifier()->getEntityTypeId())
			->isWelcomeTourEnabled()
		;
		$isWelcomeTourAutomaticallyEnabled = (CopilotRunAutomatically::getInstance())
			->setEntityTypeId($this->getContext()->getIdentifier()->getEntityTypeId())
			->isWelcomeTourEnabled()
		;
		$isWelcomeTourManuallyEnabled = (CopilotRunManually::getInstance())
			->setEntityTypeId($this->getContext()->getIdentifier()->getEntityTypeId())
			->isWelcomeTourEnabled()
		;

		return (new Payload())
			->addValueInt('activityId', $activityId)
			->addValueInt('ownerTypeId', $this->getContext()->getEntityTypeId())
			->addValueInt('ownerId', $this->getContext()->getEntityId())
			->addValueString('languageTitle', $this->getAIService()->getAILanguage(TranscribeCallRecording::TYPE_ID))
			->addValueBoolean('isWelcomeTourEnabled', $isWelcomeTourEnabled)
			->addValueBoolean('isWelcomeTourAutomaticallyEnabled', $isWelcomeTourAutomaticallyEnabled)
			->addValueBoolean('isWelcomeTourManuallyEnabled', $isWelcomeTourManuallyEnabled)
		;
	}

	final protected function getScenarios(): array
	{
		if ($this->isAIScope() && $this->getAIService()->isFieldsFillingWrong())
		{
			return [
				Scenario::CONFIRM_FIELDS_SCENARIO,
				Scenario::CALL_SCORING_SCENARIO,
			];
		}

		return [
			Scenario::FILL_FIELDS_SCENARIO,
			Scenario::CALL_SCORING_SCENARIO,
		];
	}

	final protected function canShowAIActions(): bool
	{
		return count($this->fetchAudioRecordList()) > 0;
	}

	final protected function createViewCopilotSummaryItem(array $list): ?ActionBarItem
	{
		if (!$this->canShowAIActions())
		{
			return null;
		}

		$activityId = $this->getActivityId();
		$languageTitle = $this->getAIService()->getAILanguage(SummarizeCallTranscription::TYPE_ID);
		$barItemAction = (new JsEvent('Call:ShowCopilotSummary'))
			->addActionParamInt('activityId', $activityId)
			->addActionParamInt('ownerTypeId', $this->getContext()->getEntityTypeId())
			->addActionParamInt('ownerId', $this->getContext()->getEntityId())
			->addActionParamString('languageTitle', $languageTitle)
		;

		return (new ActionBarItem())
			->setSize(ActionBarItem::SIZE_SM)
			->setDesign(ActionBarItem::DESIGN_COPILOT)
			->setAction($barItemAction)
			->setText(
				Loc::getMessage(
					'CRM_TIMELINE_BLOCK_CALL_ACTION_BAR_COPILOT_SUMMARY',
					['#COPILOT_NAME#' => AIManager::getCopilotName()]
				)
			)
		;
	}

	final protected function canMoveTo(): bool
	{
		return $this->isScheduled() && $this->isVoxImplant($this->fetchOriginId());
	}

	final protected function getDeleteConfirmationText(): string
	{
		$title = $this->getAssociatedEntityModel()?->get('SUBJECT') ?? '';

		return match ($this->fetchDirection())
		{
			CCrmActivityDirection::Incoming => Loc::getMessage('CRM_TIMELINE_INCOMING_CALL_DELETION_CONFIRM', ['#TITLE#' => $title]),
			CCrmActivityDirection::Outgoing => Loc::getMessage('CRM_TIMELINE_OUTGOING_CALL_DELETION_CONFIRM', ['#TITLE#' => $title]),
			default => parent::getDeleteConfirmationText(),
		};
	}

	final protected function fetchAudioRecordList(): array
	{
		$originId = $this->fetchOriginId();
		if (!$this->isVoxImplant($originId))
		{
			return [];
		}

		if (!empty($this->getAssociatedEntityModel()?->get('MEDIA_FILE_INFO')['URL']))
		{
			return [[
				'VIEW_URL' => (string)$this->getAssociatedEntityModel()?->get('MEDIA_FILE_INFO')['URL']
			]];
		}

		return parent::fetchAudioRecordList();
	}

	// region Build content blocks
	private function buildDeadlineBlock(): ?ContentBlockWithTitle
	{
		if (!$this->isPlanned())
		{
			return null;
		}

		$deadline = $this->getDeadline();
		if (!$deadline)
		{
			return null;
		}

		$updateDeadlineAction = null;
		if ($this->isScheduled())
		{
			$updateDeadlineAction = $this->getChangeDeadlineAction();
		}

		$editableDateBlock = (new ContentBlock\EditableDate())
			->setStyle(ContentBlock\EditableDate::STYLE_PILL)
			->setDate($deadline)
			->setAction($updateDeadlineAction)
			->setBackgroundColor(
				$this->isScheduled()
					? ContentBlock\EditableDate::BACKGROUND_COLOR_WARNING
					: null
			)
		;
		return (new ContentBlockWithTitle())
			->setFixedWidth(false)
			->setAlignItems('center')
			->setTitle(Loc::getMessage('CRM_TIMELINE_ITEM_CALL_COMPLETE_TO'))
			->setInline()
			->setContentBlock($editableDateBlock)
		;
	}

	private function buildCallDateTimeBlock(): ?ContentBlock
	{
		if ($this->isPlanned() || $this->fetchDirection() === CCrmActivityDirection::Outgoing)
		{
			return null;
		}

		$culture = Application::getInstance()->getContext()->getCulture();
		$callInfo = $this->fetchInfo();
		$startTime = $callInfo['CALL_START_DATE'] instanceof DateTime
			? $callInfo['CALL_START_DATE']
			: $this->getModel()->getDate()
		;
		$startTime = CCrmDateTimeHelper::getUserTime($startTime, $this->getContext()->getUserId())->getTimestamp();
		$dateTimeValue = sprintf(
			'%s %s',
			FormatDate($culture?->getLongDateFormat(), $startTime),
			FormatDate($culture?->getShortTimeFormat(), $startTime),
		);

		$block = new LineOfTextBlocks();
		$blockTitle = ContentBlockFactory::createTitle(Loc::getMessage('CRM_TIMELINE_BLOCK_TITLE_CALL_DATE_TIME'))
			->setColor(Text::COLOR_BASE_60)
		;
		$block->addContentBlock('callDateTimeBlockTitle', $blockTitle);

		$valueBlock = (new Text())
			->setValue($dateTimeValue)
			->setColor(Text::COLOR_BASE_70)
			->setFontWeight(Text::FONT_WEIGHT_MEDIUM)
		;

		$block->addContentBlock("callDateTimeBlock", $valueBlock);

		return $block;
	}

	private function buildResponsibleUserBlock(): ?ContentBlock
	{
		$data = $this->getUserData($this->getAssociatedEntityModel()?->get('RESPONSIBLE_ID'));
		if (empty($data))
		{
			return null;
		}

		$url = isset($data['SHOW_URL']) ? new Uri($data['SHOW_URL']) : null;
		$textOrLink = ContentBlockFactory::createTextOrLink($data['FORMATTED_NAME'], $url ? new Redirect($url) : null);

		return (new ContentBlockWithTitle())
			->setTitle(Loc::getMessage("CRM_TIMELINE_BLOCK_TITLE_RESPONSIBLE_USER"))
			->setContentBlock($textOrLink->setIsBold(true))
			->setInline()
		;
	}

	private function buildSubjectBlock(): ?ContentBlock
	{
		if (!$this->isPlanned())
		{
			return null;
		}

		$subject = $this->getAssociatedEntityModel()?->get('SUBJECT');
		if (empty($subject))
		{
			return null;
		}

		return (new ContentBlockWithTitle())
			->setFixedWidth(false)
			->setTitle(Loc::getMessage("CRM_TIMELINE_BLOCK_TITLE_THEME"))
			->setContentBlock(ContentBlockFactory::createTitle((string)$subject))
			->setInline()
		;
	}

	private function buildAdditionalInfoBlock(): ?ContentBlock
	{
		$callInfo = $this->fetchInfo();
		if (empty($callInfo))
		{
			return null;
		}

		$callInfoBlockIsEmpty = true;
		$block = new LineOfTextBlocks();

		// 1st element - phone number
		$portalNumber = $callInfo['PORTAL_LINE']['FULL_NAME'] ?? $callInfo['PORTAL_NUMBER'];
		$formattedValue = PhoneNumber\Parser::getInstance()?->parse($portalNumber)->format();
		if (!empty($formattedValue))
		{
			$block
				->addContentBlock(
					'info1',
					ContentBlockFactory::createTitle(
						Loc::getMessage(
							$this->fetchDirection() === CCrmActivityDirection::Incoming
								? 'CRM_TIMELINE_BLOCK_CALL_ADDITIONAL_INFO_1'
								: 'CRM_TIMELINE_BLOCK_CALL_ADDITIONAL_INFO_1_OUT'
						)
					)->setColor(Text::COLOR_BASE_50)

				)
				->addContentBlock('phoneNumber', ContentBlockFactory::createTitle($formattedValue))
			;

			$callInfoBlockIsEmpty = false;
		}

		// 2nd element - call duration
		$duration = (int)$callInfo['DURATION'];
		if ($duration > 0)
		{
			if (!$callInfoBlockIsEmpty)
			{
				// add delimiter before first block
				$block
					->addContentBlock(
						'delimiter',
						ContentBlockFactory::createTitle(
							html_entity_decode(self::BLOCK_DELIMITER)
						)->setColor(Text::COLOR_BASE_50)
					)
				;
			}

			$block
				->addContentBlock(
					'info2',
					ContentBlockFactory::createTitle(
						Loc::getMessage('CRM_TIMELINE_BLOCK_CALL_ADDITIONAL_INFO_2')
					)->setColor(Text::COLOR_BASE_50)
				)
				->addContentBlock(
					'duration',
					ContentBlockFactory::createTitle(
						Duration::format($duration)
					)->setColor(Text::COLOR_BASE_50)
				)
			;

			$callInfoBlockIsEmpty = false;
		}

		return $callInfoBlockIsEmpty ? null : $block;
	}

	private function buildClientMarkBlock(): ?ContentBlock
	{
		$clientMark = ClientMark::getByCallVote((int)$this->getAssociatedEntityModel()?->get('RESULT_MARK'));
		if (!isset($clientMark))
		{
			return null;
		}

		$callInfo = $this->fetchInfo();

		return (new ClientMark())
			->setMark($clientMark)
			->setText(
				Loc::getMessage(
					'CRM_TIMELINE_BLOCK_CLIENT_MARK_TEXT_MSGVER_1',
					['#MARK#' => (int)($callInfo['CALL_VOTE'] ?? 0)]
				)
			)
		;
	}

	private function buildDescriptionBlock(): ?EditableDescription
	{
		$input = (string)$this->getAssociatedEntityModel()?->get($this->isScheduled() ? 'DESCRIPTION' : 'DESCRIPTION_RAW');
		$description = $this->fetchDescription($input);
		if (empty($description))
		{
			return null;
		}

		return (new EditableDescription())
			->setText($description)
			->setEditable(false)
			->setHeight(EditableDescription::HEIGHT_LONG)
		;
	}

	private function buildCommentBlock(): ?EditableDescription
	{
		$comment = (string) ($this->fetchInfo()['COMMENT'] ?? null);
		if (empty($comment))
		{
			return null;
		}

		return (new EditableDescription())
			->setText($comment)
			->setEditable(false)
			->setHeight(EditableDescription::HEIGHT_LONG)
		;
	}

	private function buildCallScoringBlock(): ?ContentBlock
	{
		if (!$this->isAIScope() || !$this->canShowAIActions())
		{
			return null;
		}

		$activityId = $this->getActivityId();
		$scriptData = $this->fetchCallScoringScript($activityId);
		if (empty($scriptData))
		{
			return null;
		}

		$jobId = $scriptData['JOB_ID'] ?? null;
		$scoreDescription = $this->fetchScoringDescription($jobId);
		if (empty($scoreDescription))
		{
			return null;
		}

		$communication = $this->getAssociatedEntityModel()?->get('COMMUNICATION') ?? [];
		$userData = $this->getUserData($this->getAssociatedEntityModel()?->get('RESPONSIBLE_ID'));
		$created = $this->getAssociatedEntityModel()?->get('CREATED');
		$createdTimestamp = $created ? (new DateTime($created))->getTimestamp() : 0;

		$action = (new JsEvent('Call:OpenCallScoringResult'))
			->addActionParamInt('activityId', $activityId)
			->addActionParamInt('ownerTypeId', $this->getContext()->getEntityTypeId())
			->addActionParamInt('ownerId', $this->getContext()->getEntityId())
			->addActionParamInt('activityCreated', $createdTimestamp)
			->addActionParamString('clientDetailUrl', isset($communication['SHOW_URL']) ? new Uri($communication['SHOW_URL']) : null)
			->addActionParamString('clientFullName', $communication['TITLE'] ?? '')
			->addActionParamString('userPhotoUrl', $userData['PHOTO_URL'] ?? '')
			->addActionParamInt('jobId', $jobId)
			->addActionParamInt('assessmentSettingsId', $scriptData['ASSESSMENT_SETTING_ID'] ?? null)
		;

		return (new ContentBlock\Copilot\CallScoringV2())
			->setTitle($scriptData['TITLE'] ?? '')
			->setScore($scriptData['ASSESSMENT'] ?? 0)
			->setScoreLowBorder($scriptData['LOW_BORDER'] ?? 0)
			->setScoreHighBorder($scriptData['HIGH_BORDER'] ?? 0)
			->setScoreDescription($scoreDescription)
			->setAction($action)
		;
	}

	private function buildGroupBlocks(array $blocks): ContentBlock\GroupBlocks
	{
		$groupedBlock = new ContentBlock\GroupBlocks();

		if (isset($blocks['audio']))
		{
			$audioBlock = (clone $blocks['audio'])->setScopeWeb();
			$groupedBlock->addBlock('audio', $audioBlock);

			if (isset($blocks['callDateTime']))
			{
				$callDateTimeBlock = (clone $blocks['callDateTime'])->setScopeWeb();
				$additionalInfoBlock = (new LineOfTextBlocks())->addContentBlock('callDateTime', $callDateTimeBlock);
				if (isset($blocks['clientMark']))
				{
					$clientMarkBlock = (clone $blocks['clientMark'])->setScopeWeb();
					$additionalInfoBlock->addContentBlock('clientMark', $clientMarkBlock);
				}

				$groupedBlock->addBlock('additionalInfo', $additionalInfoBlock);
			}

			$callScoringBlock = $this->buildCallScoringBlock();
			if (isset($callScoringBlock))
			{
				$groupedBlock->addBlock('callScoring', $callScoringBlock->setScopeWeb());
			}
		}

		return $groupedBlock;
	}
	// endregion

	// region Internal utils
	private function getCallButton(): ?Button
	{
		$communication = $this->getAssociatedEntityModel()?->get('COMMUNICATION') ?? [];
		if (empty($communication))
		{
			return null;
		}

		$type = $this->isScheduled() ? Button::TYPE_PRIMARY : Button::TYPE_SECONDARY;
		$button = new Button(Loc::getMessage('CRM_TIMELINE_BUTTON_CALL'), $type);
		$makeCallAction = function (string $phone) use ($communication) {
			return (new JsEvent('Call:MakeCall'))
				->addActionParamInt('activityId', $this->getActivityId())
				->addActionParamInt('entityTypeId', (int)$communication['ENTITY_TYPE_ID'])
				->addActionParamInt('entityId', (int)$communication['ENTITY_ID'])
				->addActionParamInt('ownerTypeId', $this->getContext()->getEntityTypeId())
				->addActionParamInt('ownerId', $this->getContext()->getEntityId())
				->addActionParamString('phone', $phone)
				->addActionParamString('formattedName', $communication['TITLE'])
				->addActionParamBoolean('showName', $communication['SHOW_NAME'])
			;
		};
		$phoneList = $this->fetchPhoneList($communication['ENTITY_TYPE_ID'], $communication['ENTITY_ID']);
		if (count($phoneList) > 1)
		{
			$phoneMenu = new Menu();
			foreach ($phoneList as $item)
			{
				$title = empty($item['COMPLEX_NAME'])
					? sprintf('%s', $item['VALUE_FORMATTED'])
					: sprintf('%s: %s', $item['COMPLEX_NAME'], $item['VALUE_FORMATTED']);

				$phoneMenu->addItem(
					sprintf('phone_menu_%d_%d', $this->getActivityId(), $item['ID']),
					(new Menu\MenuItem($title))->setAction($makeCallAction((string)$item['VALUE']))
				);
			}

			$button->setAction(new ShowMenu($phoneMenu));
		}
		else
		{
			$button->setAction($makeCallAction((string)$communication['VALUE']));
		}

		return $button;
	}

	private function fetchScoringDescription(?int $jobId): ?string
	{
		/** @var Result<ScoreCallPayload>|null $result */
		$result = $this->getAIService()->getAIJobResult(ScoreCall::TYPE_ID, $jobId);
		if ($result?->isSuccess())
		{
			$description = empty($result?->getPayload()?->overallSummary)
				? $result?->getPayload()?->recommendations
				: $result?->getPayload()?->overallSummary
			;
			if (empty($description))
			{
				return null;
			}

			$sentences = preg_split('/(?<=[.?!])\s+/u', $description, 3, PREG_SPLIT_NO_EMPTY) ?: [];

			return implode(' ', array_slice($sentences, 0, 1));
		}

		return null;
	}

	private function fetchInfo(): array
	{
		$result = $this->getAssociatedEntityModel()?->get('CALL_INFO') ?? [];
		if (!empty($result))
		{
			return $result;
		}

		$originId = $this->fetchOriginId();

		return $this->isVoxImplant($originId)
			? VoxImplantManager::getCallInfo(mb_substr($originId, 3)) ?? []
			: [];
	}

	private function fetchPhoneList(int $entityTypeId, int $entityId): array
	{
		$result = [];

		$dbResult = CCrmFieldMulti::GetList(['ID' => 'asc'], [
			'ENTITY_ID' => CCrmOwnerType::ResolveName($entityTypeId),
			'ELEMENT_ID' => $entityId,
			'TYPE_ID' => 'PHONE'
		]);
		while ($fields = $dbResult->Fetch())
		{
			$value = $fields['VALUE'] ?? '';
			if (empty($value))
			{
				continue;
			}

			$result[] = [
				'ID' => $fields['ID'],
				'VALUE' => $value,
				'VALUE_TYPE' => $fields['VALUE_TYPE'],
				'VALUE_FORMATTED' => PhoneNumber\Parser::getInstance()?->parse($value)->format(),
				'COMPLEX_ID' => $fields['COMPLEX_ID'],
				'COMPLEX_NAME' => CCrmFieldMulti::GetEntityNameByComplex($fields['COMPLEX_ID'], false)
			];
		}

		return $result;
	}

	private function fetchDescription(string $input): string
	{
		if (empty($input))
		{
			return '';
		}

		$settings = $this->getAssociatedEntityModel()?->get('SETTINGS');
		if (isset($settings['IS_DESCRIPTION_ONLY']) && $settings['IS_DESCRIPTION_ONLY']) // new description format
		{
			return trim($input);
		}

		$parts = explode("\n", $input);
		if (str_starts_with($parts[0], Loc::getMessage('CRM_TIMELINE_BLOCK_DESCRIPTION_EXCLUDE_1')))
		{
			return '';
		}

		if (str_starts_with($parts[0], Loc::getMessage('CRM_TIMELINE_BLOCK_DESCRIPTION_EXCLUDE_2')))
		{
			return '';
		}

		return $input;
	}

	private function fetchDirection(): int
	{
		return (int)$this->getAssociatedEntityModel()?->get('DIRECTION');
	}

	private function fetchOriginId(): string
	{
		return (string)$this->getAssociatedEntityModel()?->get('ORIGIN_ID');
	}

	private function fetchCallScoringScript(int $activityId): ?array
	{
		return AiQualityAssessmentController::getInstance()->getByActivityIdAndJobId($activityId);
	}

	private function fetchTranscriptionState(): ?string
	{
		if (!$this->isAIScope() || $this->isMissedCall())
		{
			return null;
		}

		$jobResult = $this->getAIService()->getAIJobResult(TranscribeCallRecording::TYPE_ID);
		if (isset($jobResult))
		{
			return match (true)
			{
				$jobResult->isPending() => Audio::TRANSCRIPTION_PENDING,
				$jobResult->isSuccess() => Audio::TRANSCRIPTION_SUCCESS,
				$jobResult->isErrorsLimitExceeded() => Audio::TRANSCRIPTION_FAILED,
				default => Audio::TRANSCRIPTION_EMPTY,
			};
		}

		return Audio::TRANSCRIPTION_EMPTY;
	}

	private function isMissedCall(): bool
	{
		$settings = $this->getAssociatedEntityModel()?->get('SETTINGS');

		return isset($settings['MISSED_CALL']) && $settings['MISSED_CALL'];
	}

	private function isVoxImplant(?string $originId): bool
	{
		return isset($originId) && VoxImplantManager::isVoxImplantOriginId($originId);
	}

	private function isTranscribed(array $input): bool
	{
		return isset($input['HAS_TRANSCRIPT']) && $input['HAS_TRANSCRIPT'];
	}
	// endregion

	// region Logo utils
	private function getLogoTypeByDirection(int $direction): string
	{
		return match ($direction)
		{
			CCrmActivityDirection::Incoming => $this->isMissedCall()
				? Layout\Common\Logo::CALL_DEFAULT
				: Layout\Common\Logo::CALL_INCOMING,
			CCrmActivityDirection::Outgoing => Layout\Common\Logo::CALL_OUTGOING,
			default => Layout\Common\Logo::CALL_DEFAULT,
		};
	}

	private function applyLogoModifications(Layout\Body\Logo $logo, int $direction, bool $hasAudio): Layout\Body\Logo
	{
		if ($direction === CCrmActivityDirection::Incoming)
		{
			if ($this->isMissedCall())
			{
				return $logo
					->setIconType(Layout\Body\Logo::ICON_TYPE_FAILURE)
					->setAdditionalIconType(Layout\Body\Logo::ICON_TYPE_FAILURE)
					->setAdditionalIconCode('arrow')
				;
			}

			if ($hasAudio)
			{
				return $logo->setAdditionalIconCode('call-incoming');
			}

			return $logo;
		}

		// CCrmActivityDirection::Outgoing
		if ($hasAudio)
		{
			return $logo->setAdditionalIconCode('call-outgoing');
		}

		if (!$this->isPlanned() && !($this->fetchInfo()['SUCCESSFUL'] ?? true))
		{
			return $logo
				->setAdditionalIconType(Layout\Body\Logo::ICON_TYPE_FAILURE)
				->setAdditionalIconCode('cross');
		}

		return $logo;
	}
	// endregion
}
