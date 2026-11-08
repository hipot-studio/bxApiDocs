<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Entity\Activity\SetupTemplateActivity;

/**
 * A single constant declared by a setup wizard form item.
 *
 * Flat, behaviourless snapshot of one `constant` item of the SetupTemplateActivity form: it carries
 * the fields the form itself produces, plus the 1-based position of the item so a caller can point
 * at the place a verdict is about. Positions are counted the way the parser counts them, so they
 * match the positions the wizard owner sees in the settings panel.
 */
final readonly class SetupConstantDeclaration
{
	/**
	 * @param array<string, string> $options Choice options as a value => label map.
	 * @param array<string, mixed> $settings Field-type settings of the constant.
	 * @param string|array $default Default value, normalized the way the parser normalizes it.
	 */
	public function __construct(
		public string $code,
		public string $name,
		public string $type,
		public string $description,
		public bool $multiple,
		public array $options,
		public array $settings,
		public string|array $default,
		public int $blockPosition,
		public int $itemPosition,
	) {}
}
