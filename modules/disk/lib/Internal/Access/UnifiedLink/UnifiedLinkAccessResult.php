<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Access\UnifiedLink;

class UnifiedLinkAccessResult
{
	/** @var array<string, UnifiedLinkAccessLevel> */
	protected array $accessLevels = [];

	/**
	 * @param UnifiedLinkAccessSource $source
	 * @param UnifiedLinkAccessLevel $accessLevel
	 * @return void
	 */
	public function addAccessLevel(
		UnifiedLinkAccessSource $source,
		UnifiedLinkAccessLevel $accessLevel,
	): void
	{
		if ($accessLevel === UnifiedLinkAccessLevel::Denied)
		{
			return;
		}

		$this->accessLevels[$source->value] = $accessLevel;
	}

	/**
	 * @return UnifiedLinkAccessLevel
	 */
	public function getMaxAccessLevel(): UnifiedLinkAccessLevel
	{
		$maxAccessLevel = UnifiedLinkAccessLevel::Denied;

		foreach ($this->accessLevels as $accessLevel)
		{
			if ($accessLevel->value > $maxAccessLevel->value)
			{
				$maxAccessLevel = $accessLevel;

				if ($maxAccessLevel->isMax())
				{
					break;
				}
			}
		}

		return $maxAccessLevel;
	}

	public function getAccessLevel(UnifiedLinkAccessSource $source): UnifiedLinkAccessLevel
	{
		return $this->accessLevels[$source->value] ?? UnifiedLinkAccessLevel::Denied;
	}

	/**
	 * @return bool
	 */
	public function isOnlyPermissionSystemAccess(): bool
	{
		$permissionSystemAccessLevel = $this->accessLevels[UnifiedLinkAccessSource::PermissionSystem->value] ?? null;

		return count($this->accessLevels) === 1
			&& $permissionSystemAccessLevel instanceof UnifiedLinkAccessLevel
			&& $permissionSystemAccessLevel->value > UnifiedLinkAccessLevel::Denied->value;
	}
}
