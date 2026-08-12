<?php
namespace Bitrix\Landing\AI\SiteBuilder\Parser;

interface TolerantJsonParserInterface
{
	public function parse(string $payload): mixed;

	public function parseArray(string $payload): ?array;

	public function parseObject(string $payload): ?array;
}
