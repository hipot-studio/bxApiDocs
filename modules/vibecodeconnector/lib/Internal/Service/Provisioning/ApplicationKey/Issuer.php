<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Provisioning\ApplicationKey;

use Bitrix\Rest\Public\Contract\Application\RestApplicationInterface;
use Bitrix\Vibecodeconnector\Internal\Integration\Rest\MarketSubscriptionGate;
use Bitrix\Vibecodeconnector\Internal\Integration\Rest\PersonalApplicationInstaller;
use Bitrix\Vibecodeconnector\Internal\Service\Provisioning\EntryPoint;
use Bitrix\Vibecodeconnector\Internal\Service\Provisioning\PermissionSource;

final class Issuer
{
	private readonly PersonalApplicationInstaller $installer;

	public function __construct(
		private readonly EntryPoint $entryPoint,
		private readonly PermissionSource\Policy $permissionSource = new PermissionSource\Policy(
			new PermissionSource\Settings(),
		),
		?PersonalApplicationInstaller $installer = null,
		private readonly MarketSubscriptionGate $subscriptionGate = new MarketSubscriptionGate(),
	) {
		$this->installer = $installer ?? new PersonalApplicationInstaller($entryPoint);
	}

	/**
	 * @param string[] $scopes
	 * @param array<string, string> $menuTitles
	 */
	public function issue(
		int $userId,
		string $handlerUrl,
		array $scopes,
		string $title,
		bool $onlyApi = true,
		bool $mobile = false,
		string $installUrl = '',
		array $menuTitles = [],
		?string $applicationToken = null,
	): RestApplicationInterface
	{
		$this->subscriptionGate->ensureAvailable();

		return $this->permissionSource->isVibecodeSource()
			? $this->installer->forceInstall(
				$userId,
				$handlerUrl,
				$scopes,
				$title,
				$onlyApi,
				$mobile,
				$installUrl,
				$menuTitles,
				$applicationToken,
			)
			: $this->installer->install(
				$userId,
				$handlerUrl,
				$scopes,
				$title,
				$onlyApi,
				$mobile,
				$installUrl,
				$menuTitles,
				$applicationToken,
			);
	}
}
