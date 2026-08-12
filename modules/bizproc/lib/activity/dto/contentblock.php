<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Dto;

use Bitrix\Main\Type\Contract\Arrayable;

/**
 * Display-only payload rendered inside a node's content block on the designer canvas.
 *
 * Resolved on demand from the activity's current configuration; it is never persisted
 * into activity Properties, the workflow template, runtime or export.
 */
final class ContentBlock implements Arrayable
{
	public function __construct(
		public readonly string $text,
	) {}

	public function toArray(): array
	{
		return ['text' => $this->text];
	}
}
