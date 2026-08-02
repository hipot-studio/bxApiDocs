<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateReverser;

final readonly class WizardMeta
{
	/**
	 * @param array<string, true> $constantKeys map of constant ids that appear in wizard blocks (any block).
	 *        Empty array means a SetupTemplateActivity exists but contains no wizard items —
	 *        all constants must be marked `showInWizard: false` in the reversed source.
	 * @param array<string, PerConstantWizard> $perConstantWizard wizard meta attached to the
	 *        FIRST constant of each non-base titled block (mirrors builder, which opens a new
	 *        block exactly on the constant whose `wizardTitle` is set).
	 */
	public function __construct(
		public ?string $title,
		public ?string $description,
		public array $constantKeys,
		public array $perConstantWizard,
	) {}
}
