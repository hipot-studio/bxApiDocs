<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Request\Application\Embedding;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;

class ListEmbeddingRequest extends ListRequest
{
	#[NotEmpty]
	public string $clientId;
}
