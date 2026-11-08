<?php

namespace Bitrix\Crm\Integration\Landing\Vibe;

use Bitrix\Main\Result;

interface VibeGateway
{
	public function findByEmbedId(string $embedId): ?VibeReference;

	public function findById(int $vibeId): ?VibeReference;

	public function create(string $embedId): ?VibeReference;

	public function delete(VibeReference $reference): Result;
}
