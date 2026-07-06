<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Request\Application\Embedding;

use Bitrix\Main\Validation\Rule;
use Bitrix\Rest\V3\Interaction\Request\Request;

class DeleteEmbeddingRequest extends Request
{
	#[Rule\NotEmpty]
	public string $clientId;

	#[Rule\NotEmpty]
	public string $placement;

	public ?string $handler = null;

	public ?int $userId = null;
}
