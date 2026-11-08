<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding;

/**
 * Direct embedding options of a CRM form.
 *
 * @internal
 */
final readonly class Embedding
{
	public function __construct(
		public int $formId,
		public bool $formActive,
		public bool $resourcesReady,
		public EmbeddingVariant $inline,
		public EmbeddingVariant $click,
		public EmbeddingVariant $auto,
		public ?string $publicUrl = null,
	)
	{
	}
}
