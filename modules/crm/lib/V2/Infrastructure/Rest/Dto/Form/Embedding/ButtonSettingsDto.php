<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Embedding;

use Bitrix\Rest\V3\Dto\Dto;

/**
 * The button the display on click is opened by.
 *
 * A form whose button was never configured has no stored block at all, so the texts, the font and
 * the alignment come back as null.
 */
final class ButtonSettingsDto extends Dto
{
	public bool $use;

	public ?string $text;

	public ?string $font;

	public ?string $align;

	public bool $plain;

	public bool $rounded;

	public bool $outlined;

	public ?string $decoration;

	public ButtonColorSettingsDto $color;
}
