<?php

namespace Bitrix\Crm\Service\Timeline\Item\LogMessage;

use Bitrix\Crm\Service\Timeline\Item\LogMessage;
use Bitrix\Crm\Service\Timeline\Layout\Action\Redirect;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\ContentBlockFactory;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\LineOfTextBlocks;
use Bitrix\Crm\Service\Timeline\Layout\Common\Icon;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Uri;

class Copied extends LogMessage
{
	public function getType(): string
	{
		return 'Copied';
	}

	public function getIconCode(): ?string
	{
		return Icon::INFO;
	}

	public function getTitle(): ?string
	{
		$entityTypeId = $this->getModel()->getAssociatedEntityTypeId();

		return Loc::getMessage(
			'CRM_TIMELINE_LOG_COPIED_TITLE_'
				. \CCrmOwnerType::ResolveName($entityTypeId)
		) ?? Loc::getMessage('CRM_TIMELINE_LOG_COPIED_TITLE');
	}

	public function getContentBlocks(): ?array
	{
		$result = [];

		$settings = $this->getModel()->getSettings();
		$base = $settings['COPY_TARGET'] ?? [];
		$entityTypeId = (int)($base['ENTITY_TYPE_ID'] ?? 0);
		$entityId = (int)($base['ENTITY_ID'] ?? 0);

		if ($entityId > 0 && \CCrmOwnerType::IsDefined($entityTypeId))
		{
			\CCrmOwnerType::TryGetEntityInfo($entityTypeId, $entityId, $entityInfo, false);

			$caption =
				(
					Loc::getMessage('CRM_TIMELINE_LOG_COPIED_CAPTION_'
						. \CCrmOwnerType::ResolveName($entityTypeId))
				) ?? \CCrmOwnerType::GetDescription($entityTypeId)
			;
			$title = (string)($entityInfo['TITLE'] ?? '');
			$url = isset($entityInfo['SHOW_URL']) ? new Uri($entityInfo['SHOW_URL']) : null;

			$result['baseItem'] = (new LineOfTextBlocks())
				->addContentBlock(
					'title',
					ContentBlockFactory::createTitle($caption)
				)
				->addContentBlock(
					'data',
					ContentBlockFactory::createTextOrLink(
						$title,
						$url ? new Redirect($url) : null
					)->setIsBold(true)
				)
			;
		}

		return $result;
	}
}
