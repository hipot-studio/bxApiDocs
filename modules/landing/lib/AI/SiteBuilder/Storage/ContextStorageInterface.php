<?php
namespace Bitrix\Landing\AI\SiteBuilder\Storage;

use Bitrix\Landing\AI\SiteBuilder\Dto\DevContextDto;

interface ContextStorageInterface
{
	public function load(): DevContextDto;

	public function save(DevContextDto $context): void;

	public function reset(): void;
}
