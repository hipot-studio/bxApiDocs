<?php

namespace Bitrix\Tasks\Integration\UI\EntitySelector;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\TemplateAccessController;
use Bitrix\Tasks\Slider\Path\PathMaker;
use Bitrix\Tasks\Slider\Path\TemplatePathMaker;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Public\Provider\Template\TemplateRecentProvider;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\RecentItem;
use Bitrix\UI\EntitySelector\SearchQuery;
use Bitrix\UI\EntitySelector\Tab;
use CTaskTemplates;

class TaskTemplateProvider extends BaseProvider
{
	protected const ENTITY_ID = 'task-template';
	protected const LIMIT = 30;
	private const MOBILE_CONTEXT = 'mobile-task';

	protected int $templateId;
	protected bool $withFooter = true;
	protected bool $withTab = false;
	protected TemplateRecentProvider $templateRecentProvider;

	public function __construct(array $options = [])
	{
		parent::__construct();

		$this->templateId = $options['templateId'] ?? 0;
		$this->withFooter = $options['withFooter'] ?? true;
		$this->withTab = $options['withTab'] ?? false;
		$this->templateRecentProvider = Container::getInstance()->get(TemplateRecentProvider::class);
	}

	public function isAvailable(): bool
	{
		return $GLOBALS['USER']->isAuthorized();
	}

	public function getItems(array $ids): array
	{
		return $this->getTemplateItems(['ids' => $ids]);
	}

	public function getSelectedItems(array $ids): array
	{
		return $this->getTemplateItems(['ids' => $ids]);
	}

	public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		$dialog->addItems(
			$this->getTemplateItems(['searchQuery' => $searchQuery->getQuery()])
		);
	}

	public function fillDialog(Dialog $dialog): void
	{
		if ($this->isMobileContext($dialog))
		{
			$this->fillMobileDialog($dialog);
		}
		else
		{
			$this->fillWebDialog($dialog);
		}

		if ($this->withFooter)
		{
			$dialog->setFooter('BX.Tasks.EntitySelector.Footer', $this->getFooterOptions($dialog->getContext()));
		}

		$this->addTab($dialog);
	}

	protected function addTab(Dialog $dialog): void
	{
		if (!$this->withTab)
		{
			return;
		}

		$tab = new Tab([
			'id' => static::ENTITY_ID,
			'title' => Loc::getMessage('TASKS_UI_ENTITY_SELECTOR_TASK_TEMPLATE_PROVIDER_TEMPLATES'),
			'stub' => true,
			'icon' => [
				'default' => 'template-task',
			],
		]);

		$dialog->addTab($tab);
	}

	private function fillMobileDialog(Dialog $dialog): void
	{
		$recentItemIds = $this->getRecentItemsIds($dialog);
		$remainingSlots = static::LIMIT - count($recentItemIds);
		if ($remainingSlots > 0)
		{
			$templateItems = $this->getTemplateItems([
				'excludeIds' => $recentItemIds,
				'limit' => $remainingSlots,
			]);

			$dialog->addRecentItems($templateItems);
		}
	}

	private function fillWebDialog(Dialog $dialog): void
	{
		$this->fillWithBusinessRecentItems($dialog);

		$remainingSlots = static::LIMIT - $dialog->getItemCollection()->count();
		if ($remainingSlots > 0)
		{
			$templateItems = $this->getTemplateItems([
				'excludeIds' => $this->getRecentItemsIds($dialog),
				'limit' => $remainingSlots,
			]);

			$dialog->addItems($templateItems);
		}
	}

	private function getFooterOptions(string $context): array
	{
		$userId = (int)CurrentUser::get()->getId();
		$templateAddUrl = (new TemplatePathMaker(0, PathMaker::EDIT_ACTION, $userId))
			->addQueryParam('context', $context)
			->makeEntityPath();

		return [
			'templateAddUrl' => $templateAddUrl,
			'canCreateTemplate' => TemplateAccessController::can($userId, ActionDictionary::ACTION_TEMPLATE_CREATE),
			'context' => $context,
		];
	}

	private function fillWithBusinessRecentItems(Dialog $dialog): void
	{
		$dialog->cleanRecentItems();

		$templateIds = $this->templateRecentProvider->getRecentIds($GLOBALS['USER']->getId());
		$templates = $this->getTemplates(['ids' => $templateIds]);

		$templateIds = array_fill_keys($templateIds, null);
		$templates = array_replace($templateIds, $templates);

		foreach ($templates as $id => $title)
		{
			if ($dialog->getItemCollection()->count() >= static::LIMIT)
			{
				break;
			}

			$dialog->addRecentItem(static::makeItem($id, $title));
		}
	}

	private function isMobileContext(Dialog $dialog): bool
	{
		return $dialog->getContext() === self::MOBILE_CONTEXT;
	}

	private function getRecentItemsIds(Dialog $dialog): array
	{
		$recentItems = $dialog->getRecentItems()->getAll();

		return array_map(
			static function (RecentItem $item) {
				return $item->getId();
			},
			$recentItems
		);
	}

	private function getTemplateItems(array $options = []): array
	{
		return $this->makeTemplateItems($this->getTemplates($options));
	}

	private function getTemplates(array $options = []): array
	{
		$options = array_merge($this->getOptions(), $options);
		$templates = [];

		$order = ['ID' => 'desc'];
		$filter = $this->getFilterByOptions($options);
		$parameters = [
			'USER_ID' => $GLOBALS['USER']->getId(),
		];

		$navigation = [
			'NAV_PARAMS' => [
				'nTopCount' => $options['limit'] ?? static::LIMIT,
			],
		];
		$select = ['ID', 'TITLE'];

		$templatesResult = CTaskTemplates::GetList($order, $filter, $navigation, $parameters, $select);
		while ($template = $templatesResult->Fetch())
		{
			$templates[$template['ID']] = $template['TITLE'];
		}

		return $templates;
	}

	private function getFilterByOptions(array $options): array
	{
		$filter = [];

		if (
			array_key_exists('searchQuery', $options)
			&& $options['searchQuery'] !== ''
		)
		{
			$filter['%TITLE'] = $options['searchQuery'];
		}

		if (
			array_key_exists('ids', $options)
			&& is_array($options['ids'])
			&& !empty($options['ids'])
		)
		{
			$filter['ID'] = $options['ids'];
		}

		if (
			array_key_exists('excludeIds', $options)
			&& is_array($options['excludeIds'])
			&& !empty($options['excludeIds'])
		)
		{
			$filter['!ID'] = $options['excludeIds'];
		}

		$filter['!ID'][] = $this->templateId;

		return $filter;
	}

	private function makeTemplateItems(array $templates): array
	{
		return self::makeItems($templates);
	}

	private static function makeItems(array $templates): array
	{
		$result = [];
		foreach ($templates as $id => $title)
		{
			if (!empty($title))
			{
				$result[] = static::makeItem($id, $title);
			}
		}

		return $result;
	}

	protected static function makeItem(int $id, string $title): Item
	{
		return new Item([
			'entityId' => static::ENTITY_ID,
			'id' => $id,
			'title' => $title,
			'tabs' => ['recents', static::ENTITY_ID],
		]);
	}

	public static function getTemplateUrl(): string
	{
		$userId = (int)CurrentUser::get()->getId();

		return (new TemplatePathMaker(0, PathMaker::DEFAULT_ACTION, $userId))->makePathForEntitySelector();
	}

	public static function getTemplateLinkTitle(): string
	{
		return Loc::getMessage('TASKS_UI_ENTITY_SELECTOR_TASK_TEMPLATE_PROVIDER_ITEM_LINK_TITLE');
	}
}
