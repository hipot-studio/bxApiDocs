<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Embedding;

use Bitrix\Rest\V3\Dto\Dto;

/**
 * One way of placing the form, with the snippet it is placed by and the link it is previewed under.
 *
 * A variant without configurable parameters carries neither settings nor allowed values, so both
 * blocks are null for it.
 */
final class EmbeddingVariantDto extends Dto
{
	public ?string $code;

	public string $previewUrl;

	public ?ViewSettingsDto $settings;

	public ?ViewOptionsDto $options;
}
