<?php

namespace Bitrix\Crm\Service\Timeline\Layout\Footer;

use Bitrix\Crm\Service\Timeline\Layout\Base;

class Menu extends Base
{
    protected ?MenuItem $deleteItem = null;
    protected ?MenuItem $pinItem = null;

    protected array $menuItems = [];

    public function addItem(string $id, MenuItem $item): self
    {
        $this->menuItems[$id] = $item;

        return $this;
    }

    /**
     * @return MenuItem[]
     */
    public function getItems(): array
    {
        return $this->menuItems;
    }

    /**
     * @param MenuItem[] $menuItems
     */
    public function setItems(array $menuItems): self
    {
        $this->menuItems = [];
        foreach ($menuItems as $id => $menuItem) {
            $this->addItem((string) $id, $menuItem);
        }

        return $this;
    }

    public function getItemById(string $id): ?MenuItem
    {
        return $this->menuItems[$id] ?? null;
    }

    public function toArray(): array
    {
        return [
            'items' => $this->getItems(),
        ];
    }
}
