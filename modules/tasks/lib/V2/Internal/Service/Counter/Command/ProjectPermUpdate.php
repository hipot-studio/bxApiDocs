<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Counter\Command;

use Bitrix\Tasks\Internals\Counter\Event\EventDictionary;

class ProjectPermUpdate extends AbstractPayload
{
	public function __construct
	(
		public string $featurePerm,
	) {
	}

	public function getCommand(): string
	{
		return EventDictionary::EVENT_PROJECT_PERM_UPDATE;
	}

	/** @return array{FEATURE_PERM: string} */
	public function toArray(): array
	{
		return ['FEATURE_PERM' => $this->featurePerm];
	}
}
