<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Internal\Integration\Intranet;

use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\Main\SystemException;

/**
 * Whether a tool of the portal is switched on, as the tools of the intranet answer it. The tools manager comes off
 * the factory the composition root hands over (stafftrack/.settings.php) and is asked for at every question: building
 * this service includes no intranet, and the answer is the one of the manager the whole request shares.
 */
class ToolAvailabilityService
{
	/** @var (\Closure(): ?ToolsManager)|null */
	private readonly ?\Closure $toolsManagerFactory;

	/**
	 * @param (\Closure(): ?ToolsManager)|null $toolsManagerFactory the tools manager of the intranet, null where the
	 *     intranet is not installed; without a factory the service answers as a portal without the intranet
	 */
	public function __construct(?\Closure $toolsManagerFactory = null)
	{
		$this->toolsManagerFactory = $toolsManagerFactory;
	}

	/**
	 * @throws SystemException without the intranet, which has no tools to ask about
	 */
	public function isToolAvailable(string $toolId): bool
	{
		$toolsManager = $this->toolsManagerFactory === null ? null : ($this->toolsManagerFactory)();
		if ($toolsManager === null)
		{
			throw new SystemException('intranet tools are not available');
		}

		return $toolsManager->checkAvailabilityByToolId($toolId);
	}
}
