<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\WebForm;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Configuration\FormConfiguration;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormAccessDeniedException;
use Bitrix\Crm\V2\Internal\Repository\WebForm\FormConfigurationRepository;

/**
 * Reads the capabilities of the CRM form editor after checking its subject permission.
 *
 * @internal
 */
final class FormConfigurationReader
{
	private readonly \Closure $permissionChecker;
	private readonly \Closure $configurationLoader;

	/**
	 * @param null|\Closure(int): bool $permissionChecker
	 * @param null|\Closure(int): FormConfiguration $configurationLoader
	 */
	public function __construct(
		?\Closure $permissionChecker = null,
		?\Closure $configurationLoader = null,
	)
	{
		$this->permissionChecker = $permissionChecker ?? static fn (int $userId): bool =>
			Container::getInstance()
				->getUserPermissions($userId)
				->webForm()
				->canRead()
		;
		$this->configurationLoader = $configurationLoader ?? static fn (int $userId): FormConfiguration =>
			(new FormConfigurationRepository())->get($userId)
		;
	}

	/**
	 * Returns the portal capabilities available to the specified user.
	 *
	 * @throws FormAccessDeniedException the user may not read CRM forms
	 */
	public function read(int $userId): FormConfiguration
	{
		if (!(bool)($this->permissionChecker)($userId))
		{
			throw new FormAccessDeniedException();
		}

		return ($this->configurationLoader)($userId);
	}
}
