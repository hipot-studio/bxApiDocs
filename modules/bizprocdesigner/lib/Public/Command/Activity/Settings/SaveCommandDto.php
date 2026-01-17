<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Public\Command\Activity\Settings;

final class SaveCommandDto
{
	public function __construct(
		public readonly SaveCommandActivityDto $activity,
		public readonly array $documentType,
		public readonly array $template,
		public readonly array $variables = [],
		public readonly array $parameters = [],
		public readonly array $constants = [],
	)
	{}
}
