<?php

namespace Bitrix\Crm\Service\Timeline\Item\LogMessage;

use Bitrix\Crm\Model\ActivityPingOffsetsTable;
use Bitrix\Crm\Service\Timeline\Item\AssociatedEntityModel;
use Bitrix\Crm\Service\Timeline\Item\LogMessage;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\ContentBlockFactory;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\EditableDescription;
use Bitrix\Crm\Service\Timeline\Layout\Common\Icon;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;

class Ping extends LogMessage
{
	protected AssociatedEntityModel $entityModel;

	public function getType(): string
	{
		return 'Ping';
	}

	public function getIconCode(): ?string
	{
		return Icon::CLOCK;
	}

	public function getTitle(): ?string
	{
		return Loc::getMessage('CRM_TIMELINE_LOG_PING_TITLE_NEW');
	}

	public function getContentBlocks(): ?array
	{
		$this->entityModel = $this->getAssociatedEntityModel();
		$deadlineStr = $this->entityModel->get('DEADLINE');
		if (empty($deadlineStr))
		{
			return [];
		}

		$created = $this->getDate();
		$offset = 0;
		if ($created)
		{
			$deadline = DateTime::createFromUserTime($deadlineStr);
			$offset = ($deadline->getTimestamp() - $created->getTimestamp()) / 60; // minutes
		}

		$pingOffset = null;
		if ($offset === 0)
		{
			$pingOffset = 0;
		}
		else
		{
			$pingOffset = $this->getModel()->getSettings()['PING_OFFSET'] ?? null;
			if (is_null($pingOffset))
			{
				$offsetLists = ActivityPingOffsetsTable::getOffsetsByActivityId($this->getModel()->getAssociatedEntityId());
				$pingOffset = $offsetLists[1] ?? null;
			}
			else
			{
				$pingOffset = (int)($pingOffset / 60);
			}
		}

		$blocks = $this->buildContentBlocks();

		if (!is_null($pingOffset) && $pingOffset >= 0)
		{
			$startBlockTitle = $this->getOffsetText($pingOffset);
			$blocks['start'] = ContentBlockFactory::createTitle($startBlockTitle);
		}

		return $blocks;
	}

	protected function buildContentBlocks(): array
	{
		$pingText = (string)$this->entityModel->get('DESCRIPTION');
		if ($pingText === '')
		{
			$providerId = $this->entityModel->get('PROVIDER_ID');
			if ($providerId)
			{
				$provider = \CCrmActivity::GetProviderById($providerId);
				$pingText = $provider::getActivityTitle([
					'SUBJECT' => (string)$this->entityModel->get('SUBJECT'),
					'COMPLETED' => 'N',
				]);
			}
			else
			{
				$pingText = (string)$this->entityModel->get('SUBJECT');
			}
		}

		return [
			'subject' => (new EditableDescription())
				->setText($pingText)
				->setEditable(false)
				->setHeight(EditableDescription::HEIGHT_SHORT),
		];
	}

	private function getOffsetText(int $pingOffset): string
	{
		if ($pingOffset === 0)
		{
			return Loc::getMessage('CRM_TIMELINE_LOG_PING_ACTIVITY_STARTED_NEW');
		}
		$hours = (int)($pingOffset / 60);
		$minutes = $pingOffset % 60;
		if (!$hours)
		{
			return Loc::getMessagePlural('CRM_TIMELINE_LOG_PING_ACTIVITY_START_NEW', $minutes, ['#OFFSET#' => $minutes]);
		}
		if (!$minutes)
		{
			return Loc::getMessagePlural('CRM_TIMELINE_LOG_PING_ACTIVITY_START_NEW_HOURS', $hours, ['#HOURS#' => $hours]);
		}

		return Loc::getMessagePlural('CRM_TIMELINE_LOG_PING_ACTIVITY_START_NEW_HOURS_MINS', $hours, ['#HOURS#' => $hours, '#MINUTES#' => $minutes]);
	}
}