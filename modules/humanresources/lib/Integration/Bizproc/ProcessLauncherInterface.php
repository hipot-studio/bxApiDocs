<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\Bizproc;

use Bitrix\Main\Result;

/**
 * Substitution point for the bizproc start call: the engine entry point is final with a static factory,
 * so only this boundary can be replaced in tests.
 */
interface ProcessLauncherInterface
{
	/**
	 * Raises the new employee trigger event so its automation scenarios start later, outside the
	 * current request. Never throws on its own: an unusable engine and a missing employee are both
	 * answered by the result, so the caller distinguishes outcomes by it alone.
	 */
	public function launchForNewEmployee(int $userId): Result;

	public function launchForPositionChanges(StructurePositionChange ...$changes): Result;
}
