<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder;

final class BranchResult
{
	public function __construct(
		public readonly NodeOutput $output,
		/** @var NodeOutput[] */
		public readonly array $unconnectedOutputs,
		public readonly int $maxStepIndex,
	) {}
}
