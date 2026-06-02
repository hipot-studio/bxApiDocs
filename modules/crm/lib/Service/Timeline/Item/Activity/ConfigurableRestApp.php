<?php

namespace Bitrix\Crm\Service\Timeline\Item\Activity;

use Bitrix\Crm\Activity\Entity\ConfigurableRestApp\Dto\ActionDto;
use Bitrix\Crm\Activity\Entity\ConfigurableRestApp\Dto\ContentBlockDto;
use Bitrix\Crm\Activity\Entity\ConfigurableRestApp\Dto\FooterButtonDto;
use Bitrix\Crm\Activity\Entity\ConfigurableRestApp\Dto\FooterMenuDto;
use Bitrix\Crm\Activity\Entity\ConfigurableRestApp\Dto\LayoutDto;
use Bitrix\Crm\Activity\Entity\ConfigurableRestApp\Dto\MenuItemDto;
use Bitrix\Crm\Activity\Entity\ConfigurableRestApp\Dto\TagDto;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Service\Timeline\Item\Activity;
use Bitrix\Crm\Service\Timeline\Item\Model;
use Bitrix\Crm\Service\Timeline\Layout;
use Bitrix\Crm\Service\Timeline\Layout\Action;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock;
use Bitrix\Crm\Service\Timeline\Layout\Factory\RestAppConfigurable\ActionFactory;
use Bitrix\Crm\Service\Timeline\Layout\Factory\RestAppConfigurable\ContentBlockFactory;
use Bitrix\Crm\Service\Timeline\Layout\Footer\Button;
use Bitrix\Crm\Service\Timeline\Layout\Header\Tag;
use Bitrix\Crm\Service\Timeline\Layout\Icon;
use Bitrix\Crm\Service\Timeline\Layout\Menu\BadgeText;
use Bitrix\Crm\Service\Timeline\Layout\Menu\MenuItem;
use Bitrix\Crm\Service\Timeline\Layout\Menu\MenuItemDelimiter;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Json;
use Bitrix\Rest\AppTable;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

class ConfigurableRestApp extends Activity
{
	private ?LayoutDto $layoutDto = null;
	private ?int $restAppId = null;
	protected ActionFactory $actionFactory;
	protected ContentBlockFactory $contentBlockFactory;

	protected function getActionFactory(): ActionFactory
	{
		if (!isset($this->actionFactory))
		{
			$this->actionFactory = new ActionFactory($this, $this->getRestAppClientId(), $this->getRestAppId());
		}

		return $this->actionFactory;
	}

	protected function getContentBlocksFactory(): ContentBlockFactory
	{
		if (!isset($this->contentBlockFactory))
		{
			$this->contentBlockFactory = new ContentBlockFactory($this, $this->getActionFactory());
		}

		return $this->contentBlockFactory;
	}

	public function needShowRestAppLayoutBlocks(): bool
	{
		return false;
	}

	public static function isModelValid(Model $model): bool
	{
		try
		{
			$layout = Json::decode($model->getAssociatedEntityModel()?->get('PROVIDER_DATA'));

			return !empty($layout);
		}
		catch (ArgumentException)
		{
			return false;
		}
	}

	protected function getActivityTypeId(): string
	{
		return 'ConfigurableRestApp';
	}

	public function getIconCode(): ?string
	{
		$icon = $this->getLayoutDto()->icon;
		if ($this->isValidDto($icon))
		{
			return $icon->code ?? '';
		}

		return '';
	}

	/**
	 * Icon of timeline record
	 *
	 */
	public function getIcon(): ?Icon
	{
		$icon = parent::getIcon();
		if (!$icon)
		{
			return null;
		}

		$iconData = Layout\Common\Icon::initFromCode($icon->getCode())->getData();
		if (!$iconData) // wrong icon code was provided
		{
			return null;
		}
		if (!$iconData['isSystem'])
		{
			$icon->setBackgroundUri($iconData['fileUri']);
		}

		return $icon;
	}

	public function getTitle(): string
	{
		$header = $this->getLayoutDto()->header;
		if ($header)
		{
			return $header->title ?? '';
		}

		return '';
	}

	public function getTitleAction(): ?Action
	{
		$header = $this->getLayoutDto()->header;
		if ($header && $this->isValidDto($header->titleAction))
		{
			return $this->createAction($header->titleAction);
		}

		return null;
	}

	public function getLogo(): ?Layout\Body\Logo
	{
		$body = $this->getLayoutDto()->body;
		if (!$body || !$body->logo)
		{
			return null;
		}

		$logoCode = $body->logo->code;

		if (!$logoCode)
		{
			return null;
		}

		$logo = Layout\Common\Logo::getInstance($logoCode)->createLogo();
		if (!$logo)
		{
			return null;
		}

		return $logo->setAction($this->createAction($body->logo->action));
	}

	public function getContentBlocks(): array
	{
		if (!$this->getLayoutDto()->body)
		{
			return [];
		}
		$blocks = $this->getLayoutDto()->body->blocks;
		if (empty($blocks))
		{
			return [];
		}

		$result = [];
		foreach ($blocks as $blockId => $blockDto)
		{
			$block = $this->createContentBlock($blockDto);
			if ($block)
			{
				$result[(string)$blockId] = $block;
			}
		}

		return $result;
	}

	public function getButtons(): array
	{
		if (!$this->getLayoutDto()->footer)
		{
			return [];
		}
		$buttons = $this->getLayoutDto()->footer->buttons;
		if (empty($buttons))
		{
			return [];
		}

		$result = [];
		foreach ($buttons as $buttonId => $buttonDto)
		{
			$button = $this->createFooterButton($buttonDto);
			if ($button)
			{
				$result[(string)$buttonId] = $button;
			}
		}

		return $result;
	}

	public function getMenuItems(): array
	{
		$needAddPinMenuItem = true;
		$needAddPostponeMenuItem = true;
		$needAddDeleteMenuItem = true;
		$extraMenuItems = [];
		$footerMenu = $this->getFooterMenu();
		$hasValidExplicitSections = $this->hasValidExplicitSections();
		if ($footerMenu)
		{
			$needAddPinMenuItem = $footerMenu->showPinItem ?? true;
			$needAddPostponeMenuItem = $footerMenu->showPostponeItem ?? true;
			$needAddDeleteMenuItem = $footerMenu->showDeleteItem ?? true;
			$extraMenuItems = $hasValidExplicitSections || (empty($footerMenu->sections) && !$this->hasInvalidExplicitSections())
				? ($footerMenu->items ?? [])
				: []
			;
		}
		if (!$this->getDeadline() || !$this->isScheduled())
		{
			$needAddPostponeMenuItem = false;
		}
		if ($this->isScheduled())
		{
			$needAddPinMenuItem = false;
		}

		$hasSections = $hasValidExplicitSections;

		$result = [];

		$extraMenuItemsAdded = false;
		foreach ($extraMenuItems as $menuId => $menuItemDto)
		{
			$menuItem = $this->createMenuItem($menuItemDto);
			if ($menuItem)
			{
				$result[(string)$menuId] = $menuItem;
				$extraMenuItemsAdded = true;
			}
		}
		if ($extraMenuItemsAdded && !$hasSections)
		{
			$result['delim1'] = (new MenuItemDelimiter())->setSort(9000);
		}

		$stdMenuItemsAdded = false;
		if ($needAddPinMenuItem)
		{
			$this->addPinMenuItems($result);
			if ($hasSections)
			{
				foreach (['pin', 'unpin'] as $pinKey)
				{
					if (isset($result[$pinKey]))
					{
						$result[$pinKey]->setSectionCode('system');
					}
				}
			}
			$stdMenuItemsAdded = true;
		}
		if ($needAddPostponeMenuItem)
		{
			$postponeMenuItem = $this->createPostponeMenuItem($this->getActivityId());
			if ($postponeMenuItem)
			{
				if ($hasSections)
				{
					$postponeMenuItem->setSectionCode('system');
				}
				$result['postpone'] = $postponeMenuItem;
				$stdMenuItemsAdded = true;
			}
		}
		if ($needAddDeleteMenuItem)
		{
			$deleteMenuItem = $this->createDeleteMenuItem($this->getActivityId());
			if ($deleteMenuItem)
			{
				if ($hasSections)
				{
					$deleteMenuItem->setSectionCode('system');
				}
				$result['delete'] = $deleteMenuItem;
				$stdMenuItemsAdded = true;
			}
		}
		if ($stdMenuItemsAdded && !$hasSections)
		{
			$result['delim2'] = (new MenuItemDelimiter())->setSort(10000);
		}

		$aboutAction = $this->createOpenAppAction();
		$aboutAction->addActionParamString('context', 'aboutMenuItem');

		$aboutAppItem = (new MenuItem(Loc::getMessage('CRM_TIMELINE_CONFIGURABLE_APP_MENU_ITEM_ABOUT')))
			->setAction($aboutAction)
			->setSort(10001)
			->setIcon(Outline::INFO_CIRCLE)
			->setScopeWeb()
		;
		if ($hasSections)
		{
			$aboutAppItem->setSectionCode('about');
		}
		$result['aboutApp'] = $aboutAppItem;

		return $result;
	}

	public function getTags(): ?array
	{
		$header = $this->getLayoutDto()->header;
		if (!$header || empty($header->tags))
		{
			return [];
		}

		$result = [];
		foreach ($header->tags as $tagId => $tagDto)
		{
			$tag = $this->createTag($tagDto);
			if ($tag)
			{
				$result[(string)$tagId] = $tag;
			}
		}
		return $result;
	}

	public function needShowNotes(): bool
	{
		return (!$this->getLayoutDto()->footer || $this->getLayoutDto()->footer->showNote !== false);
	}

	private function getLayoutDto(): LayoutDto
	{
		if (!$this->layoutDto)
		{
			try
			{
				$layout = Json::decode($this->getAssociatedEntityModel()->get('PROVIDER_DATA'));
			}
			catch (ArgumentException)
			{
				$layout = [];
			}

			$this->layoutDto = new LayoutDto((array)$layout);
		}

		return $this->layoutDto;
	}

	private function createTag(?TagDto $tagDto): ?Tag
	{
		if (!$this->isValidDto($tagDto))
		{
			return null;
		}

		return (new Tag($tagDto->title, $tagDto->type))
			->setAction($this->createAction($tagDto->action))
		;
	}

	private function createContentBlock(?ContentBlockDto $contentBlockDto): ?ContentBlock
	{
		return $this->getContentBlocksFactory()->createByDto($contentBlockDto);
	}

	private function createFooterButton(?FooterButtonDto $buttonDto): ?Button
	{
		if (!$this->isValidDto($buttonDto))
		{
			return null;
		}

		return (new Button($buttonDto->title, $buttonDto->type))
			->setScope($buttonDto->scope)
			->setHideIfReadonly($buttonDto->hideIfReadonly)
			->setAction($this->createAction($buttonDto->action))
		;
	}

	private function mapDtoSections(): array
	{
		$footerMenu = $this->getFooterMenu();
		if ($footerMenu === null || !$this->hasValidExplicitSections())
		{
			return [];
		}

		$sections = [];
		foreach ($footerMenu->sections as $sectionDto)
		{
			$section = ['code' => $sectionDto->code];
			if ($sectionDto->title !== null)
			{
				$section['title'] = (string)$sectionDto->title;
			}
			if ($sectionDto->design !== null)
			{
				$section['design'] = $sectionDto->design;
			}
			$sections[] = $section;
		}

		return $sections;
	}

	/**
	 * Returns app-declared sections followed by synthetic sections for builder-owned items.
	 *
	 * @return array<int, array{code: string, title?: string, design?: string}>
	 */
	public function getMenuSections(): array
	{
		$appSections = $this->mapDtoSections();
		if (empty($appSections))
		{
			return [];
		}

		// Append synthetic sections for system-injected items
		if ($this->hasSystemMenuItems())
		{
			$appSections[] = [
				'code' => 'system',
			];
		}
		$appSections[] = [
			'code' => 'about',
		];

		return $appSections;
	}

	private function hasSystemMenuItems(): bool
	{
		$needAddPinMenuItem = true;
		$needAddPostponeMenuItem = true;
		$needAddDeleteMenuItem = true;

		$footerMenu = $this->getFooterMenu();
		if ($footerMenu)
		{
			$needAddPinMenuItem = $footerMenu->showPinItem ?? true;
			$needAddPostponeMenuItem = $footerMenu->showPostponeItem ?? true;
			$needAddDeleteMenuItem = $footerMenu->showDeleteItem ?? true;
		}
		if (!$this->getDeadline() || !$this->isScheduled())
		{
			$needAddPostponeMenuItem = false;
		}
		if ($this->isScheduled())
		{
			$needAddPinMenuItem = false;
		}

		return $needAddPinMenuItem || $needAddPostponeMenuItem || $needAddDeleteMenuItem;
	}

	private function getFooterMenu(): ?FooterMenuDto
	{
		return $this->getLayoutDto()->footer?->menu;
	}

	private function hasValidExplicitSections(): bool
	{
		$footerMenu = $this->getFooterMenu();
		$sections = $footerMenu?->sections;
		if (!is_array($sections) || empty($sections))
		{
			return false;
		}

		foreach ($footerMenu->getValidationErrors() as $error)
		{
			if (in_array($error->getCode(), [
				FooterMenuDto::ERROR_RESERVED_SECTION_CODE,
				FooterMenuDto::ERROR_DUPLICATE_SECTION_CODE,
				FooterMenuDto::ERROR_MISSING_SECTION_CODE,
				FooterMenuDto::ERROR_UNKNOWN_SECTION_CODE,
			], true))
			{
				return false;
			}
		}

		$declaredCodes = [];
		foreach ($sections as $sectionDto)
		{
			if ($sectionDto === null || $sectionDto->hasValidationErrors())
			{
				return false;
			}

			$code = (string)$sectionDto->code;
			if ($code === '' || in_array($code, FooterMenuDto::RESERVED_SECTION_CODES, true))
			{
				return false;
			}
			if (in_array($code, $declaredCodes, true))
			{
				return false;
			}

			$declaredCodes[] = $code;
		}

		foreach (($footerMenu->items ?? []) as $menuItemDto)
		{
			if ($menuItemDto === null || $menuItemDto->hasValidationErrors())
			{
				continue;
			}

			$sectionCode = $menuItemDto->sectionCode;
			if (!is_string($sectionCode) || $sectionCode === '' || !in_array($sectionCode, $declaredCodes, true))
			{
				return false;
			}
		}

		return true;
	}

	private function hasInvalidExplicitSections(): bool
	{
		$footerMenu = $this->getFooterMenu();
		if (!$footerMenu)
		{
			return false;
		}

		foreach ($footerMenu->getValidationErrors() as $error)
		{
			if (in_array($error->getCode(), [
				FooterMenuDto::ERROR_RESERVED_SECTION_CODE,
				FooterMenuDto::ERROR_DUPLICATE_SECTION_CODE,
				FooterMenuDto::ERROR_MISSING_SECTION_CODE,
				FooterMenuDto::ERROR_UNKNOWN_SECTION_CODE,
			], true))
			{
				return true;
			}

			$field = $error->getCustomData()['FIELD'] ?? null;
			if ($field === 'sections' || (is_string($field) && str_starts_with($field, 'sections[')))
			{
				return true;
			}
		}

		return false;
	}

	private function createMenuItem(?MenuItemDto $menuItemDto): ?MenuItem
	{
		if (!$this->isValidDto($menuItemDto))
		{
			return null;
		}

		$item = (new MenuItem((string)$menuItemDto->title))
			->setScope($menuItemDto->scope)
			->setHideIfReadonly($menuItemDto->hideIfReadonly)
			->setAction($this->createAction($menuItemDto->action))
		;

		if ($menuItemDto->subtitle !== null)
		{
			$item->setSubtitle((string)$menuItemDto->subtitle);
		}
		if ($menuItemDto->design !== null)
		{
			$item->setDesign($menuItemDto->design);
		}
		if ($menuItemDto->isSelected !== null)
		{
			$item->setIsSelected($menuItemDto->isSelected);
		}
		if ($menuItemDto->isLocked !== null)
		{
			$item->setIsLocked($menuItemDto->isLocked);
		}
		if ($menuItemDto->badgeText !== null)
		{
			$item->setBadgeText(new BadgeText(
				(string)$menuItemDto->badgeText->title,
				$menuItemDto->badgeText->color,
			));
		}
		if ($menuItemDto->sectionCode !== null)
		{
			$item->setSectionCode($menuItemDto->sectionCode);
		}

		return $item;
	}

	private function createAction(?ActionDto $actionDto): ?Action
	{
		if (!$this->isValidDto($actionDto))
		{
			return null;
		}

		return $this->getActionFactory()->createByDto($actionDto);
	}

	private function createOpenAppAction(): Action\JsEvent
	{
		return $this->getActionFactory()->createOpenAppAction();
	}

	private function isValidDto(?Dto $dto): bool
	{
		if (!$dto)
		{
			return false;
		}

		return !$dto->hasValidationErrors();
	}

	private function getProviderParams(): array
	{
		return $this->getAssociatedEntityModel()->get('PROVIDER_PARAMS');
	}

	private function getRestAppClientId(): ?string
	{
		$clientId = $this->getProviderParams()['clientId'];

		return $clientId ? (string)$clientId : null;
	}

	private function getRestAppId(): int
	{
		if ($this->restAppId === null)
		{
			$clientId = $this->getRestAppClientId();
			if ($clientId && Loader::includeModule('rest'))
			{
				$app = AppTable::getByClientId($clientId);
				$this->restAppId = (int)($app['ID'] ?? 0);
			}
		}

		return $this->restAppId;
	}
}
