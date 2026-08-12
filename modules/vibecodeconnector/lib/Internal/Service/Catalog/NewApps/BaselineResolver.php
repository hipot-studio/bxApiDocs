<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\NewApps;

use Bitrix\Main\Type\DateTime;

final class BaselineResolver
{
	public function __construct(
		private readonly BaselineSettings $settings = new BaselineSettings(),
	) {}

	public function resolveForUser(int $userId): DateTime
	{
		$global = $this->getOrInitGlobalBaseline();
		$personal = $this->settings->getPersonalBaselineAt($userId);

		return $personal !== null && $personal->getTimestamp() >= $global->getTimestamp()
			? $personal
			: $global;
	}

	public function markCatalogOpened(int $userId): void
	{
		if ($this->settings->getPersonalBaselineAt($userId) === null)
		{
			$this->settings->setPersonalBaselineAt($userId, $this->getOrInitGlobalBaseline());
		}
	}

	private function getOrInitGlobalBaseline(): DateTime
	{
		$global = $this->settings->getGlobalBaselineAt();
		if ($global !== null)
		{
			return $global;
		}

		$this->settings->setGlobalBaselineAt(new DateTime());

		// Return the persisted anchor rather than the just-built value: under
		// concurrent first requests every response reflects the stored option
		// instead of each using its own timestamp.
		return $this->settings->getGlobalBaselineAt() ?? new DateTime();
	}
}
