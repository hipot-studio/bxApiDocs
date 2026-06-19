<?php

declare(strict_types=1);

namespace Bitrix\TasksMobile\Dto;

final class TaskTemplateTagDto
{
	public const TYPE = 'template-tag';

	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly string $type = self::TYPE,
	) {}
}
