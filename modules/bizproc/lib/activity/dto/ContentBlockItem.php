<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Dto;

use Bitrix\Main\Type\Contract\Arrayable;

/**
 * A single labelled value of a node's settings summary, shown by the settings panel as the second
 * part of a row: "label: value".
 *
 * Both fields are ready for display: the label is a localized string and the value is already
 * resolved, so it carries neither raw identifiers nor raw substitution expressions.
 * Display-only, like the {@see ContentBlock} carrying it: never persisted into activity Properties,
 * the workflow template, runtime or export, and never converted back into a template.
 */
final class ContentBlockItem implements Arrayable
{
	public function __construct(
		public readonly string $label,
		public readonly string $value,
	) {}

	public function toArray(): array
	{
		return [
			'label' => $this->label,
			'value' => $this->value,
		];
	}
}
