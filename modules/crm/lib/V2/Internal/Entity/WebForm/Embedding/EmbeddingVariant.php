<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding;

/**
 * One direct way to place a CRM form on a page.
 *
 * @internal
 */
final readonly class EmbeddingVariant
{
	public const INLINE = 'inline';
	public const CLICK = 'click';
	public const AUTO = 'auto';

	public function __construct(
		public string $name,
		public ?string $code = null,
		public ?string $previewUrl = null,
		public ?ViewSettings $settings = null,
		public ?ViewOptions $options = null,
	)
	{
	}
}
