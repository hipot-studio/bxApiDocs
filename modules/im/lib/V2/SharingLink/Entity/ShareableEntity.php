<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\SharingLink\Entity;

interface ShareableEntity
{
	public function getId(): null|int|string;
	public static function getSharingLinkEntityType(): LinkEntityType;
	public function isExist(): bool;
}
