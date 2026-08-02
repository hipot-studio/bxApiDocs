<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateReverser;

final readonly class ParsedSetupBlock
{
	/**
	 * @param list<string> $constantIds
	 */
	public function __construct(
		public ?string $title,
		public ?string $description,
		public array $constantIds,
	) {}
}
