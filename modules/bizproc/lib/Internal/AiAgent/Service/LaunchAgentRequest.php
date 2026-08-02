<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AiAgent\Service;

use Bitrix\Bizproc\Api\Enum\Template\CreateSource;

final readonly class LaunchAgentRequest
{
	/**
	 * @param array<string, mixed> $constants
	 */
	public function __construct(
		public int $systemTemplateId,
		public int $userId,
		public array $constants = [],
		public CreateSource $source = CreateSource::Scenario,
	) {}
}
