<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Public\Command\Activity\Settings;

class SaveCommandActivityDto
{
	public function __construct(
		public readonly string $type,
		public readonly string $name,
		public readonly array $properties,
		public readonly string $title = '',
		public readonly string $editorComment = '',
		public readonly bool $isActivated = true,
	)
	{}
}
