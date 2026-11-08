<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Activity;

use Bitrix\Bizproc\Internal\Integration\Tasks\DocumentFieldTypes\ProjectFieldStateResolver;

final class ActivityControlsProjectContext
{
	private ProjectFieldStateResolver $projectFieldStateResolver;

	public function __construct(
		public readonly int $userId = 0,
		public readonly array $workflowConstants = [],
		public readonly ?array $trustedPropertyValues = null,
		?ProjectFieldStateResolver $projectFieldStateResolver = null,
	)
	{
		$this->projectFieldStateResolver = $projectFieldStateResolver ?? new ProjectFieldStateResolver();
	}

	public function getProjectFieldStateResolver(): ProjectFieldStateResolver
	{
		return $this->projectFieldStateResolver;
	}
}
