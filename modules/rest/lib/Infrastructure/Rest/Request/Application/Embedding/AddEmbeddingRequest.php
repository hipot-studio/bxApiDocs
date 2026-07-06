<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Request\Application\Embedding;

use Bitrix\Main\Validation\Rule;
use Bitrix\Rest\V3\Interaction\Request\Request;

class AddEmbeddingRequest extends Request
{
	#[Rule\NotEmpty]
	public string $clientId;

	#[Rule\NotEmpty]
	public string $placement;

	#[Rule\NotEmpty]
	#[Rule\Url]
	public string $handler;

	public int $userId = 0;

	public ?string $title = null;
	public ?string $description = null;
	public ?string $groupName = null;
	public ?array $settings = null;
	public ?array $languages = null;
}
