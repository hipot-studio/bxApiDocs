<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Sticker\CustomPacks;

use Bitrix\Im\V2\Registry;

/**
 * @implements \IteratorAggregate<int,UserPackItem>
 *  @method UserPackItem offsetGet($key)
 */
class UserPackCollection extends Registry
{}
