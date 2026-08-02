<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateReverser;

final readonly class PerConstantWizard
{
	public function __construct(
		public string $title,
		public ?string $description,
	) {}
}
