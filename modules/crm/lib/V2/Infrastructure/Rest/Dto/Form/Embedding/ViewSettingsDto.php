<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Embedding;

use Bitrix\Rest\V3\Dto\Dto;

/**
 * The display settings a variant works under.
 *
 * Only the parameters of the variant are present: `delay` belongs to the automatic display and
 * `button` to the display on click. A parameter the portal has stored no value for comes back as
 * null - the embed bundle applies its own fallback then, and this contract never invents one.
 */
final class ViewSettingsDto extends Dto
{
	public ?string $type;

	public ?string $position;

	public ?string $vertical;

	public ?int $delay;

	public ButtonSettingsDto $button;
}
