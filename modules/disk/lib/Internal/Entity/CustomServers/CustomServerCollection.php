<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Entity\CustomServers;

use Bitrix\Disk\Internal\Interface\CustomServerInterface;
use Bitrix\Main\Entity\EntityCollection;

class CustomServerCollection extends EntityCollection
{
	/**
	 * {@inheritDoc}
	 */
	protected static function getEntityClass(): string
	{
		return CustomServerInterface::class;
	}
}
