<?php

namespace Bitrix\Crm\Service\Timeline\Item\Activity;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Service\Timeline\Item\Activity;
use Bitrix\Crm\Service\Timeline\Layout\Action\JsEvent;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\GroupBlocks;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\Text;
use Bitrix\Crm\Service\Timeline\Layout\Body\Logo;
use Bitrix\Crm\Service\Timeline\Layout\Common;
use Bitrix\Crm\Service\Timeline\Layout\Footer\Button;
use Bitrix\Crm\Service\Timeline\Layout\Header\InfoHelper;
use Bitrix\Crm\Service\Timeline\Layout\Header\InfoHelperLink;
use Bitrix\Crm\Service\Timeline\Layout\Header\InfoHelperText;
use Bitrix\Crm\Service\Timeline\Layout\Header\Tag;
use Bitrix\Main\Localization\Loc;
use CCrmOwnerType;

final class EntityExclusion extends Activity
{
	private const HELP_ARTICLE_CODE = '26164810';

	protected function getActivityTypeId(): string
	{
		return 'EntityExclusion';
	}

	public function getIconCode(): ?string
	{
		return Common\Icon::CROSS_AIR;
	}

	public function getTitle(): string
	{
		$subject = $this->getAssociatedEntityModel()?->get('SUBJECT');

		return $subject ?? Loc::getMessage(
			'CRM_TIMELINE_ITEM_ENTITY_EXCLUSION_TITLE',
			[
				'#COPILOT_NAME#' => AIManager::getCopilotName(),
			]
		);
	}

	public function getInfoHelper(): ?InfoHelper
	{
		$action = (new JsEvent('Helpdesk:Open'))->addActionParamString('articleCode', self::HELP_ARTICLE_CODE);

		return (new InfoHelper())
			->setIconCode(InfoHelper::ICON_INFO)
			->setPrimaryAction($action)
			->addText(new InfoHelperText(Loc::getMessage('CRM_TIMELINE_ITEM_ENTITY_EXCLUSION_HELPER_TITLE') . ' '))
			->addLink(new InfoHelperLink(
				Loc::getMessage('CRM_TIMELINE_ITEM_ENTITY_EXCLUSION_HELPER_READ_MORE'),
				$action
			))
		;
	}

	public function getLogo(): ?Logo
	{
		return Common\Logo::getInstance(Common\Logo::IRRELEVANT_CALL)
			->createLogo()
			?->setInCircle(false)
			?->setIconType(Logo::ICON_TYPE_ORANGE)
			?->setAdditionalIconType(Logo::ICON_TYPE_FAILURE)
			?->setAdditionalIconCode($this->getIconCode())
		;
	}

	public function getContentBlocks(): array
	{
		$code = null;
		if ($this->context->getEntityTypeId() === CCrmOwnerType::Lead)
		{
			$code = 'CRM_TIMELINE_ITEM_ENTITY_EXCLUSION_EXPLANATION_LEAD';
		}
		elseif ($this->context->getEntityTypeId() === CCrmOwnerType::Deal)
		{
			$code = 'CRM_TIMELINE_ITEM_ENTITY_EXCLUSION_EXPLANATION_DEAL';
		}

		if (!$code)
		{
			return [];
		}

		$explanationBlock = (new Text())
			->setValue(Loc::getMessage($code))
			->setFontSize(Text::FONT_SIZE_SM)
			->setColor(Text::COLOR_BASE_0)
		;

		return [
			'groupOfBlocks' => $this->buildGroupBlocks(),
			'explanation' => $explanationBlock,
			'baseActivity' => $this->buildBaseActivityBlock(),
		];
	}

	public function getButtons(): array
	{
		$buttons = parent::getButtons() ?? [];

		if ($this->isScheduled())
		{
			$buttons['agreeButton'] = (new Button(Loc::getMessage('CRM_TIMELINE_ITEM_ENTITY_EXCLUSION_BUTTON_AGREE'), Button::TYPE_PRIMARY))
				->setAction(
					(new JsEvent('Activity:EntityExclusion:Exclude'))
						->addActionParamInt('activityId', $this->getActivityId())
						->addActionParamInt('ownerTypeId', $this->getContext()->getEntityTypeId())
						->addActionParamInt('ownerId', $this->getContext()->getEntityId())
				)
				->setHideIfReadonly()
			;
			$buttons['refuseButton'] = (new Button(Loc::getMessage('CRM_TIMELINE_ITEM_ENTITY_EXCLUSION_BUTTON_REFUSE'), Button::TYPE_SECONDARY))
				->setAction($this->getCompleteAction())
				->setHideIfReadonly()
			;
		}

		return $buttons;
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

	public function getTags(): ?array
	{
		$tags = [];

		if ($this->isReactionRequired())
		{
			$tags['reactionRequired'] = new Tag(
				Loc::getMessage('CRM_TIMELINE_ITEM_ENTITY_EXCLUSION_TAG_REACTION_REQUIRED'),
				Tag::TYPE_WARNING
			);
		}

		return $tags;
	}

	public function needShowNotes(): bool
	{
		return true;
	}

	// region Build content blocks
	private function buildGroupBlocks(): GroupBlocks
	{
		// we check allowed entityTypeId in getContentBlocks method
		$code = $this->context->getEntityTypeId() === CCrmOwnerType::Lead
			? 'CRM_TIMELINE_ITEM_ENTITY_EXCLUSION_SUB_DESCRIPTION_LEAD'
			: 'CRM_TIMELINE_ITEM_ENTITY_EXCLUSION_SUB_DESCRIPTION_DEAL'
		;

		$subDescriptionBlock = (new Text())
			->setValue(Loc::getMessage($code, ['#COPILOT_NAME#' => AIManager::getCopilotName()]))
			->setFontSize(Text::FONT_SIZE_SM)
			->setColor(Text::COLOR_BASE_90)
		;
		$descriptionBlock = (new Text())
			->setValue($this->getAssociatedEntityModel()?->get('DESCRIPTION'))
		;

		return (new GroupBlocks())
			->setBorderType(GroupBlocks::BORDER_TYPE_WARNING)
			->setBlocks([$subDescriptionBlock, $descriptionBlock])
		;
	}
	// endregion

	private function isReactionRequired(): bool
	{
		$settings = $this->getAssociatedEntityModel()?->get('SETTINGS');

		return isset($settings['REACTION_REQUIRES']) && $settings['REACTION_REQUIRES'];
	}
}
