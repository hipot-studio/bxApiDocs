<?php

namespace Bitrix\AI\Image\Url;

interface RuntimeInterface
{
	public function usesProxy(): bool;

	public function resolve(string $host): array;

	public function request(string $url, string $pinnedIp): array;

	public function checkAndSaveImage(string $content, string $moduleId, string $sourceName): ?int;
}
