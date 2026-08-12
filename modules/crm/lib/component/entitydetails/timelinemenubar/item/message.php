<?php

declare(strict_types=1);

namespace Bitrix\Crm\Component\EntityDetails\TimelineMenuBar\Item;

use Bitrix\Crm\Component\EntityDetails\TimelineMenuBar\Item;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Crm\Integration\SmsManager;
use Bitrix\Crm\MessageSender\UI\Editor\Scene\ItemDetails;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UI\Extension;
use Bitrix\MessageService\Public\UI\MessageEditor\Context;
use Bitrix\MessageService\Public\UI\MessageEditor\Editor;
use CCrmOwnerType;

final class Message extends Item
{
	public function isAvailable(): bool
	{
		if (!SmsManager::canUse())
		{
			return false;
		}

		if ($this->isCatalogEntityType())
		{
			return false;
		}

		if ($this->isMyCompany())
		{
			return false;
		}

		if (CCrmOwnerType::isUseDynamicTypeBasedApproach($this->getEntityTypeId()))
		{
			$factory = Container::getInstance()->getFactory($this->getEntityTypeId());

			return ($factory && $factory->isClientEnabled());
		}

		return true;
	}

	public function getId(): string
	{
		return 'message';
	}

	public function getName(): string
	{
		return (string)Loc::getMessage('CRM_TIMELINE_MENUBAR_MESSAGE');
	}

	protected function prepareSettings(): array
	{
		if (!$this->shouldRender())
		{
			// no sense rendering on item add
			return [
				'shouldRender' => false,
			];
		}

		$analytics = $this->getAnalytics();

		$context = new Context(
			customData: [
				'entityTypeId' => $this->getEntityTypeId(),
				'entityId' => $this->getEntityId(),
				'categoryId' => $this->getEntityCategoryId(),
			],
		);

		$editor = (new Editor(
			new ItemDetails(),
			$context,
		))
			->setDynamicLoad(true)
			->setAnalytics($analytics)
		;

		$editor->getLayout()->setPaddingTop('0');

		return [
			'shouldRender' => true,
			'editor' => $editor,
			'analytics' => $analytics,
		];
	}

	private function shouldRender(): bool
	{
		return $this->getEntityId() > 0;
	}

	private function getAnalytics(): array
	{
		return [
			'c_section' => Dictionary::getSectionByEntityType($this->getEntityTypeId(), $this->getEntityCategoryId()),
			'c_sub_section' => Dictionary::SUB_SECTION_DETAILS,
		];
	}

	public function loadAssets(): void
	{
		if ($this->shouldRender())
		{
			Extension::load('messageservice.message.editor.skeleton');
		}
	}
}
