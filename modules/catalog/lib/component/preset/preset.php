<?php

namespace Bitrix\Catalog\Component\Preset;

interface preset
{
    public function enable();

    public function disable();

    public function isOn(): bool;
}
