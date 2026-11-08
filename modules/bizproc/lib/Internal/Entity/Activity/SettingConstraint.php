<?php

namespace Bitrix\Bizproc\Internal\Entity\Activity;

use Bitrix\Main\Type\Contract\Arrayable;

/**
 * A rule over several settings of one block, judged after defaults and baseline values are applied.
 * The names are unique and refer to the settings or the carried names of the same block.
 */
class SettingConstraint implements Arrayable
{
	/** @var list<string> */
	public readonly array $settings;

	/**
	 * @param list<string> $settings Setting names the rule is about.
	 */
	public function __construct(
		public readonly SettingConstraintKind $kind,
		array $settings,
	)
	{
		$this->settings = array_values(array_unique(
			array_filter($settings, static fn(mixed $name) => is_string($name) && $name !== '')
		));
	}

	public function toArray(): array
	{
		return [
			'kind' => $this->kind->value,
			'settings' => $this->settings,
		];
	}
}
