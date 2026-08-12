<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Provisioning\ApiKey;

use Bitrix\Vibecodeconnector\Internal\Integration\Rest\IncomingWebhookCreator;
use Bitrix\Vibecodeconnector\Internal\Integration\Rest\MarketSubscriptionGate;
use Bitrix\Vibecodeconnector\Internal\Service\Provisioning\EntryPoint;
use Bitrix\Vibecodeconnector\Internal\Service\Provisioning\PermissionSource;

final class Issuer
{
	private readonly IncomingWebhookCreator $webhookCreator;

	public function __construct(
		private readonly EntryPoint $entryPoint,
		private readonly PermissionSource\Policy $permissionSource = new PermissionSource\Policy(
			new PermissionSource\Settings(),
		),
		?IncomingWebhookCreator $webhookCreator = null,
		private readonly MarketSubscriptionGate $subscriptionGate = new MarketSubscriptionGate(),
	) {
		$this->webhookCreator = $webhookCreator ?? new IncomingWebhookCreator($entryPoint);
	}

	/**
	 * @param string[] $scopes
	 */
	public function issue(int $userId, array $scopes, string $title): string
	{
		$this->subscriptionGate->ensureAvailable();

		return $this->permissionSource->isVibecodeSource()
			? $this->webhookCreator->forceCreate($userId, $scopes, $title)
			: $this->webhookCreator->create($userId, $scopes, $title);
	}
}
