<?php

namespace Bitrix\Crm\Security\Role\UIAdapters\AccessRights;

use Bitrix\Main\GroupTable;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\SearchQuery;
use Bitrix\UI\EntitySelector\Tab;

class sitegroupsprovider extends BaseProvider
{
    public const ENTITY_ID = 'site_groups';

    private const SEARCH_ITEMS_LIMIT = 20;

    public function __construct(array $options = [])
    {
        parent::__construct();

        $this->options['dynamicLoad'] = true;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function fillDialog(Dialog $dialog): void
    {
        $siteGroupsRecent = $dialog->getRecentItems()->getEntityItems(self::ENTITY_ID);
        $recentIds = array_keys($siteGroupsRecent);
        $items = $this->getItems($recentIds);

        array_walk(
            $items,
            static function (Item $item, int $index) use ($dialog) {
                if (empty($dialog->getContext())) {
                    $item->setSort($index);
                }
                $dialog->addRecentItem($item);
            }
        );

        $dialog->addTab(new Tab([
            'id' => 'site_groups',
            'title' => GetMessage('CRM_SITE_GROUP_PROVIDER_TITLE'),
            'stub' => true,
            'icon' => [
                'default' => '',
                'selected' => str_replace('ABB1B8', 'fff', ''),
            ],
        ]));
    }

    public function getItems(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $result = [];

        $ids = array_map(static function (string $id) {
            if (str_starts_with($id, 'G')) {
                $id = preg_replace('/^G/', '', $id);
            }

            return (int) $id;
        }, $ids);

        $groupItems = GroupTable::query()
            ->setSelect(['ID', 'NAME'])
            ->where('ANONYMOUS', 'N')
            ->whereNotNull('NAME')
            ->whereIn('ID', $ids)
            ->fetchAll()
        ;

        foreach ($groupItems as $groupItem) {
            $itemOptions = [
                'id' => 'G'.$groupItem['ID'],
                'entityId' => static::ENTITY_ID,
                'title' => $groupItem['NAME'],
            ];

            $item = new Item($itemOptions);

            $item->addTab(['site_groups']);

            $result[] = $item;
        }

        return $result;
    }

    public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
    {
        $items = GroupTable::query()
            ->setSelect(['ID', 'NAME'])
            ->where('ANONYMOUS', 'N')
            ->whereNotNull('NAME')
            ->whereLike('NAME', $searchQuery->getQuery())
            ->setLimit(self::SEARCH_ITEMS_LIMIT)
            ->fetchAll()
        ;

        foreach ($items as $item) {
            $dialog->addItem(new Item([
                'id' => $item['ID'],
                'entityId' => self::ENTITY_ID,
                'title' => $item['NAME'],
            ]));
        }
    }
}
