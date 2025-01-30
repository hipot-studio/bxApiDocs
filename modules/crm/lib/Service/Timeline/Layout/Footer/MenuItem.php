<?php

namespace Bitrix\Crm\Service\Timeline\Layout\Footer;

use Bitrix\Crm\Service\Timeline\Layout\Button;

class MenuItem extends Button
{
    protected ?string $icon = null;

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function toArray(): array
    {
        return array_merge(
            parent::toArray(),
            [
                'icon' => $this->getIcon(),
            ]
        );
    }
}
