<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Interface;

interface CustomServerWithDataInterface
{
	/**
	 * @param array|null $data
	 * @return void
	 */
	public function setData(?array $data): void;
}
