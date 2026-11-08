<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Embedding;

use Bitrix\Main\Config\Feature;
use Bitrix\Rest\Internal\Config\Feature\LegacyNonAppEmbeddings;

final class LegacyNonAppEmbeddingsAvailability
{
	public function isEnabled(): bool
	{
		return Feature::isEnabled(LegacyNonAppEmbeddings::class);
	}
}
