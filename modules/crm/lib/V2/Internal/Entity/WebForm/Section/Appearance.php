<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Section;

/**
 * @internal
 */
final readonly class Appearance
{
	public const FIELD_TITLE = 'title';
	public const FIELD_DESCRIPTION = 'description';
	public const FIELD_BUTTON_CAPTION = 'buttonCaption';
	public const FIELD_BUTTON_TEXT_COLOR = 'buttonTextColor';
	public const FIELD_BUTTON_BACKGROUND_COLOR = 'buttonBackgroundColor';
	public const FIELD_BACKGROUND_IMAGE_ID = 'backgroundImageId';
	public const FIELD_COPYRIGHT_REMOVED = 'copyrightRemoved';

	public function __construct(
		public ?string $title = null,
		public ?string $description = null,
		public ?string $buttonCaption = null,
		public ?string $buttonTextColor = null,
		public ?string $buttonBackgroundColor = null,
		public ?int $backgroundImageId = null,
		public ?bool $copyrightRemoved = null,
	)
	{
	}
}
