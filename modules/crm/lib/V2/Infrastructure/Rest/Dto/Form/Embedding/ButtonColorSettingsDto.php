<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Embedding;

use Bitrix\Rest\V3\Dto\Dto;

/**
 * The colours of the button, as they are stored: a hex value with an alpha channel, or null while
 * the portal has stored none.
 */
final class ButtonColorSettingsDto extends Dto
{
	public ?string $text;

	public ?string $textHover;

	public ?string $background;

	public ?string $backgroundHover;
}
