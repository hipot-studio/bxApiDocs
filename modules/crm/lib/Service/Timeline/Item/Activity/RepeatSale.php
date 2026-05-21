<?php

namespace Bitrix\Crm\Service\Timeline\Item\Activity;

use Bitrix\Crm\Activity\Provider;
use Bitrix\Crm\Format\TextHelper;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Dto\RepeatSale\FillRepeatSaleTipsPayload;
use Bitrix\Crm\Integration\AI\Operation\FillRepeatSaleTips;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\RepeatSale\Segment\Controller\RepeatSaleSegmentController;
use Bitrix\Crm\RepeatSale\Segment\Entity\RepeatSaleSegment;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Timeline\Context;
use Bitrix\Crm\Service\Timeline\Item\AIActivity;
use Bitrix\Crm\Service\Timeline\Layout\Action\JsEvent;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\ContentBlockFactory;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\EditableDescription;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\LineOfTextBlocks;
use Bitrix\Crm\Service\Timeline\Layout\Body\Logo;
use Bitrix\Crm\Service\Timeline\Layout\Common;
use Bitrix\Crm\Service\Timeline\Layout\Footer\Button;
use Bitrix\Main\Localization\Loc;
use CCrmContentType;

final class RepeatSale extends AIActivity
{
	private const HELPDESK_CODE_COPILOT_WARNING = '20412666';
	private const HELPDESK_CODE_REPEAT_SALE = '25376986';

	private ?RepeatSaleSegment $segment = null;
	private bool $segmentLoaded = false;

	protected function getActivityTypeId(): string
	{
		return 'RepeatSale';
	}

	public function getTitle(): string
	{
		return Loc::getMessage( 'CRM_TIMELINE_ITEM_REPEAT_SALE_TITLE');
	}

	public function getIconCode(): ?string
	{
		return Common\Icon::REPEAT_SALE;
	}

	public function getLogo(): ?Logo
	{
		return Common\Logo::getInstance(Common\Logo::REPEAT_SALE)->createLogo();
	}

	public function getContentBlocks(): array
	{
		$result = [];

		$descriptionBlock = $this->buildDescriptionBlock();
		if (isset($descriptionBlock))
		{
			$result['description'] = $descriptionBlock;
		}

		$warningBlock = $this->buildWarningBlock();
		if (isset($warningBlock))
		{
			$result['warning'] = $warningBlock;
		}

		$result['segment'] = $this->buildSegmentBlock();

		$errorBlock = $this->buildErrorBlock();
		if (isset($errorBlock))
		{
			$result['error'] = $errorBlock;
		}

		return $result;
	}

	public function getButtons(): array
	{
		$scheduleButtonType = $this->isScheduled() ? Button::TYPE_PRIMARY : Button::TYPE_SECONDARY;
		$payload = $this->getJobResult()?->getPayload();
		if ($payload instanceof FillRepeatSaleTipsPayload)
		{
			$description = Provider\RepeatSale::createDescriptionFromPayload($payload, true);
		}

		$scheduleButton = $this->getScheduleButton(
			'Activity:RepeatSale:Schedule',
			$description ?? '',
			$scheduleButtonType
		);

		return array_merge(['scheduleButton' => $scheduleButton], $this->getAIButtons());
	}

	public function getMenuItems(): array
	{
		$menuItems = parent::getMenuItems();
		unset($menuItems['view']);

		if (!$this->hasUpdatePermission())
		{
			unset($menuItems['delete']);
		}

		return $menuItems;
	}

	protected function getScenarios(): array
	{
		return [
			Scenario::REPEAT_SALE_TIPS_SCENARIO,
		];
	}

	protected function canShowAIActions(): bool
	{
		return $this->getSegment() !== null;
	}

	// region Build content blocks
	private function buildDescriptionBlock(): ?ContentBlock
	{
		// base block
		$block = (new EditableDescription())
			->setEditable(false)
			->setUseBBCodeEditor(true)
			->setBackgroundColor(
				$this->isScheduled()
					? EditableDescription::BG_COLOR_YELLOW
					: EditableDescription::BG_COLOR_WHITE
			)
		;

		$jobResult = $this->getJobResult();
		if (
			is_null($jobResult)
			|| !$jobResult->isSuccess()
		) // not processed by copilot or error
		{
			$description = $this->getSegment()?->getPrompt() ?? '';
			$headerText = $this->getDescriptionHeaderText('CRM_TIMELINE_ITEM_REPEAT_SALE_HEADER_TEXT_DEFAULT');

			return empty($description)
				? null // not found segment or empty description
				: $block
					->setCopied(true)
					->setHeaderText($headerText)
					->setText($description)
			;
		}

		// copilot in progress
		if ($jobResult->isPending())
		{
			return $block->setCopilotStatus(EditableDescription::AI_IN_PROGRESS);
		}

		// copilot return success result
		if ($jobResult->isSuccess())
		{
			$headerText = $this->getDescriptionHeaderText('CRM_TIMELINE_ITEM_REPEAT_SALE_HEADER_TEXT_COPILOT');

			return $block
				->setCopied(true)
				->setHeaderText($headerText)
				->setText($this->getDescription())
				->setCopilotStatus(EditableDescription::AI_SUCCESS)
			;
		}

		// copilot return error
		return $block
			->setText(Loc::getMessage('CRM_TIMELINE_ITEM_REPEAT_SALE_COPILOT_ERROR'))
			->setCopilotStatus(EditableDescription::AI_NONE)
		;
	}

	private function buildSegmentBlock(): LineOfTextBlocks
	{
		$segment = $this->getSegment();
		$segmentName = $segment?->getTitle() ?? Loc::getMessage('CRM_TIMELINE_ITEM_REPEAT_SALE_UNKNOWN_SCENARIO');
		$segmentId = $segment?->getId() ?? 0;

		$action = null;

		if ($segmentId > 0 && Container::getInstance()->getRepeatSaleAvailabilityChecker()->hasPermission())
		{
			$action = (new JsEvent('Activity:RepeatSale:OpenSegment'))
				->addActionParamInt('segmentId', $segmentId)
			;
		}
		elseif ($segmentId > 0)
		{
			$action = new JsEvent('Activity:RepeatSale:ShowRestrictionSlider');
		}

		$textOrLink = ContentBlockFactory::createTextOrLink($segmentName, $action);

		return (new LineOfTextBlocks())
			->addContentBlock('title', ContentBlockFactory::createTitle(Loc::getMessage('CRM_TIMELINE_ITEM_REPEAT_SALE_SCENARIO')))
			->addContentBlock('value', $textOrLink->setIsBold($segmentId > 0))
		;
	}

	private function buildWarningBlock(): ?ContentBlock
	{
		$jobResult = $this->getJobResult();
		if ($jobResult?->isSuccess())
		{
			$message = $this->getWarningText();

			return ContentBlockFactory::createFromHtmlString(
				$message,
				'copilot_warning_',
				[
					'link' => [
						'color' => ContentBlock\Text::COLOR_BASE_50,
						'size' => ContentBlock\Text::FONT_SIZE_SM,
						'decoration' => ContentBlock\Text::DECORATION_UNDERLINE,
					],
					'text' => [
						'color' => ContentBlock\Text::COLOR_BASE_50,
						'size' => ContentBlock\Text::FONT_SIZE_SM,
					],
				],
				''
			);
		}

		return null;
	}

	private function buildErrorBlock(): ?ContentBlock
	{
		$jobResult = $this->getJobResult();
		if (isset($jobResult) && !$jobResult->isSuccess())
		{
			return (new ContentBlock\ErrorBlock())
				->setTitle(Loc::getMessage('CRM_TIMELINE_ITEM_REPEAT_SALE_COPILOT_ERROR_TITLE'))
				->setDescription(Loc::getMessage('CRM_TIMELINE_ITEM_REPEAT_SALE_COPILOT_ERROR'))
				->setClosable(true)
				->setType(ContentBlock\ErrorBlock::ERROR_TYPE_AI)
			;
		}

		return null;
	}
	// endregion

	// region Internal utils
	private function getJobResult(): ?Result
	{
		return $this->getAIService()->getAIJobResult(FillRepeatSaleTips::TYPE_ID);
	}

	private function getSegment(): ?RepeatSaleSegment
	{
		if (!$this->segmentLoaded)
		{
			$params = $this->getAssociatedEntityModel()?->get('PROVIDER_PARAMS') ?? [];
			$segmentId = (int)($params['SEGMENT_ID'] ?? 0);

			$this->segment = RepeatSaleSegmentController::getInstance()->getById($segmentId);
			$this->segmentLoaded = true;
		}

		return $this->segment;
	}

	private function getDescription(): string
	{
		$description = (string)($this->getAssociatedEntityModel()?->get('DESCRIPTION') ?? '');

		// Temporarily removes [p] for mobile compatibility
		$descriptionType = (int)$this->getAssociatedEntityModel()?->get('DESCRIPTION_TYPE');
		if (
			$descriptionType === CCrmContentType::BBCode
			&& $this->getContext()->getType() === Context::MOBILE
		)
		{
			$description = TextHelper::removeParagraphs($description);
		}

		return $description;
	}

	private function getDescriptionHeaderText(string $code): ?string
	{
		return Loc::getMessage(
			$code,
			[
				'[helpdesklink]' => '<a href="' . $this->getLinkOnHelp(self::HELPDESK_CODE_REPEAT_SALE) . '" target="blank">',
				'[/helpdesklink]' => '</a>',
				'#COPILOT_NAME#' => AIManager::getCopilotName(),
			],
		);
	}

	private function getWarningText(): ?string
	{
		return Loc::getMessage(
			'CRM_TIMELINE_ITEM_REPEAT_SALE_COPILOT_WARNING',
			[
				'[helpdesklink]' => '<a href="' . $this->getLinkOnHelp(self::HELPDESK_CODE_COPILOT_WARNING) . '" target="blank">',
				'[/helpdesklink]' => '</a>',
				'#COPILOT_NAME#' => AIManager::getCopilotName(),
			],
		);
	}
	// endregion
}
