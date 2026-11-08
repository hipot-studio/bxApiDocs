<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form;

use Bitrix\Rest\V3\Dto\Dto;

final class AppearanceDto extends Dto
{
	public ?string $title;

	public ?string $description;

	public ?string $buttonCaption;

	public ?string $buttonTextColor;

	public ?string $buttonBackgroundColor;

	public ?int $backgroundImageId;

	public bool $copyrightRemoved;
}
