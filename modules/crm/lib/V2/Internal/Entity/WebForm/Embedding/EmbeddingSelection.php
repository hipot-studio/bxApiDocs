<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding;

/**
 * One embedding value selected for a REST response.
 *
 * @internal
 */
final readonly class EmbeddingSelection
{
	public const LINK = 'link';

	public function __construct(
		public string $variant,
		public ?string $code = null,
		public ?string $url = null,
	)
	{
	}
}
