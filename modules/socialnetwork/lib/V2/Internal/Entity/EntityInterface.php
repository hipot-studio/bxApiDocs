<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity;

use Bitrix\Main\Type\Contract\Arrayable;

interface EntityInterface extends \Bitrix\Main\Entity\EntityInterface, Arrayable
{
	public static function mapFromArray(array $props): static;
}
