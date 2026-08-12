<?php
namespace Bitrix\Landing\Copilot\Services\Manifest;

use Bitrix\Landing\PublicAction\AiBlock;
use Bitrix\Landing\PublicActionResult;

class AiBlockTemplateFactory
{
	public function createTemplate(string $html, array $meta = []): PublicActionResult
	{
		return AiBlock::createTemplate($html, $meta);
	}

	public function addToLanding(
		int $landingId,
		int $repoId,
		int $afterId = 0,
		int $beforeId = 0
	): PublicActionResult
	{
		return AiBlock::addToLanding($landingId, $repoId, $afterId, $beforeId);
	}
}
