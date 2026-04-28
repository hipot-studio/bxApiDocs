<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Interface;

use Bitrix\Disk\Internal\Service\CustomServerConfig;

interface CustomServerWithConfigInterface
{
	/**
	 * @param CustomServerConfig|null $config
	 * @return void
	 */
	public function setConfig(?CustomServerConfig $config): void;
}
