<?php

namespace Bitrix\Crm\Service\Timeline\Item\LogMessage;

use Bitrix\Crm\Format\TextHelper;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Timeline\Context;
use Bitrix\Crm\Service\Timeline\Item\LogMessage;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\ContentBlockFactory;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\Date;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\EditableDescription;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\LineOfTextBlocks;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\Text;
use Bitrix\Crm\Service\Timeline\Layout\Common\Icon;
use Bitrix\Crm\Timeline\LogMessage\TodoCreatedActivityData;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use CCrmActivity;

Container::getInstance()->getLocalization()->loadMessages();

class TodoCreated extends LogMessage
{
	public function getType(): string
	{
		return 'TodoCreated';
	}

	public function getIconCode(): ?string
	{
		return Icon::CIRCLE_CHECK;
	}

	public function getTitle(): ?string
	{
		$subject = $this->getModel()->getAssociatedEntityModel()?->get('SUBJECT');

		if (empty($subject))
		{
			return Loc::getMessage('CRM_TIMELINE_LOG_TODO_CREATED_TITLE');
		}

		return $subject;
	}

	public function getContentBlocks(): ?array
	{
		$settings = $this->getModel()->getSettings();
		$historyActivityData = $this->getHistoryItemModel()?->get('ASSOCIATED_ENTITY') ?? [];
		$defaultDescriptionType = isset($historyActivityData['DESCRIPTION_TYPE'])
			? (int)$historyActivityData['DESCRIPTION_TYPE']
			: null
		;
		$activityData = TodoCreatedActivityData::fromArray(
			(array)($settings['ACTIVITY_DATA'] ?? []),
			$defaultDescriptionType
		);

		$baseActivityId = $activityData->getAssociatedEntityId();
		$deadlineTimestamp = $activityData->getDeadlineTimestamp();
		$description = trim($activityData->getDescription());
		$calendarEventId = $historyActivityData['CALENDAR_EVENT_ID'] ?? null;
		$duration = null;
		if ($calendarEventId)
		{
			$duration = $activityData->getDuration();
		}

		$descriptionType = $activityData->getDescriptionType();
		if ($description !== '')
		{
			$description = $this->prepareDescription($description, $descriptionType);
			if (
				$this->getContext()->getType() === Context::MOBILE
				&& $descriptionType === \CCrmContentType::BBCode
			)
			{
				$description = TextHelper::removeParagraphs($description);
			}
		}

		$result = [];
		if ($baseActivityId)
		{
			$baseActivity = CCrmActivity::GetList(
				[],
				[
					'=ID' => $baseActivityId,
					'CHECK_PERMISSIONS' => 'N'
				],
				false,
				false,
				[
					'SUBJECT',
					'ID'
				]
			)?->Fetch();

			if ($baseActivity)
			{
				$result['baseActivity'] = (new LineOfTextBlocks())
					->addContentBlock(
						'title',
						ContentBlockFactory::createTitle(Loc::getMessage('CRM_TIMELINE_LOG_TODO_CREATED_LINK'))
					)
					->addContentBlock(
						'value',
						(new Text())
							->setValue($baseActivity['SUBJECT'] ?: Loc::getMessage('CRM_COMMON_UNTITLED'))
							->setColor(Text::COLOR_BASE_90)
					)
				;
			}
		}

		if ($deadlineTimestamp)
		{
			$result['created'] = (new LineOfTextBlocks())
				->addContentBlock(
					'title',
					ContentBlockFactory::createTitle(Loc::getMessage('CRM_TIMELINE_LOG_TODO_CREATED_DATE'))
				)
				->addContentBlock(
					'value',
					(new Date())
						->setDate(DateTime::createFromTimestamp($deadlineTimestamp))
						->setDuration($duration)
						->setColor(Text::COLOR_BASE_90)
						->setFontSize(Text::FONT_SIZE_SM)
				)
			;
		}

		if ($description)
		{
			$result['description'] = (new EditableDescription())
				->setText($description)
				->setEditable(false)
				->setHeight(EditableDescription::HEIGHT_SHORT)
				->setUseBBCodeEditor(true)
				->setAllowFiles(false)
			;
		}

		return $result;
	}

	private function prepareDescription(string $description, int $descriptionType): string
	{
		if ($descriptionType === \CCrmContentType::BBCode)
		{
			return $description;
		}

		return $this->cleanTextByType(
			TextHelper::encodeSingleUnsupportedAngleBracketTokens($description),
			$descriptionType
		);
	}

	private function cleanTextByType(string $text, int $descriptionType): string
	{
		if ($descriptionType === \CCrmContentType::Html)
		{
			return TextHelper::convertHtmlToText($text);
		}

		return TextHelper::cleanTextByType($text, $descriptionType);
	}

}
