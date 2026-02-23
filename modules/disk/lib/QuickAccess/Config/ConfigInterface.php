<?php
declare(strict_types=1);

namespace Bitrix\Disk\QuickAccess\Config;

interface ConfigInterface
{
	public const CONFIG_STORAGE = 'storage';
	public const CONFIG_KEY = 'key';

	public function getKey(): ?string;
	public function getTokenStorage(): array;
}
