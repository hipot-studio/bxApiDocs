<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Security;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Web\Json;
use Bitrix\Rest\Internal\Entity\Access\EntityType;
use Bitrix\Rest\Internal\Entity\Access\PermissionType;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\WebhookType;

final class SecurityAuditLogger
{
	public const AUDIT_WEBHOOK_CREATE = 'REST_WEBHOOK_CREATE';
	public const AUDIT_WEBHOOK_UPDATE = 'REST_WEBHOOK_UPDATE';
	public const AUDIT_WEBHOOK_DELETE = 'REST_WEBHOOK_DELETE';
	public const AUDIT_WEBHOOK_OWNER_CHANGE = 'REST_WEBHOOK_OWNER_CHANGE';
	public const AUDIT_APP_INSTALL = 'REST_APP_INSTALL';
	public const AUDIT_APP_UNINSTALL = 'REST_APP_UNINSTALL';
	public const AUDIT_APP_ACCESS_CHANGE = 'REST_APP_ACCESS_CHANGE';
	public const AUDIT_SCOPE_REQUEST = 'REST_SCOPE_REQUEST';
	public const AUDIT_SCOPE_APPROVE = 'REST_SCOPE_APPROVE';
	public const AUDIT_SCOPE_REJECT = 'REST_SCOPE_REJECT';
	public const AUDIT_ACCESS_POLICY_CHANGE = 'REST_ACCESS_POLICY_CHANGE';
	public const AUDIT_SYSTEM_USER_ACTIVATE = 'REST_SYSTEM_USER_ACTIVATE';
	public const AUDIT_SYSTEM_USER_DEACTIVATE = 'REST_SYSTEM_USER_DEACTIVATE';
	public const AUDIT_USER_AUTHORIZE = 'USER_AUTHORIZE';
	public const AUDIT_USER_REGISTER = 'USER_REGISTER';

	public function logUserAuthorized(
		int $userId,
		string $applicationType,
		int $applicationId,
		string $timePeriod,
	): void
	{
		$this->log(
			self::AUDIT_USER_AUTHORIZE,
			$userId,
			[
				'userId' => $userId,
				'method' => 'rest',
				'applicationType' => $applicationType,
				'applicationId' => $applicationId,
				'timePeriod' => $timePeriod,
			],
		);
	}

	public function logSystemUserRegistered(
		int $newUserId,
		int $originalUserId,
		int $resourceId,
		string $resourceType,
	): void
	{
		$this->log(
			self::AUDIT_USER_REGISTER,
			$newUserId,
			[
				'originalUserId' => $originalUserId,
				'newUserId' => $newUserId,
				'resourceId' => $resourceId,
				'resourceType' => $resourceType,
			],
		);
	}

	public function logWebhookCreated(
		int $actingUserId,
		int $webhookId,
		int $ownerUserId,
		array $scopes,
		WebhookType $webhookType = WebhookType::User,
	): void
	{
		$this->log(
			self::AUDIT_WEBHOOK_CREATE,
			$webhookId,
			[
				'actingUserId' => $actingUserId,
				'ownerUserId' => $ownerUserId,
				'scopes' => $scopes,
				'webhookType' => $webhookType->name,
			],
		);
	}

	public function logWebhookUpdated(
		int $actingUserId,
		int $webhookId,
		int $ownerUserId,
		array $previousScopes,
		array $newScopes,
		?string $title = null,
	): void
	{
		$this->log(
			self::AUDIT_WEBHOOK_UPDATE,
			$webhookId,
			[
				'actingUserId' => $actingUserId,
				'ownerUserId' => $ownerUserId,
				'previousScopes' => $previousScopes,
				'newScopes' => $newScopes,
				'title' => $title,
			],
		);
	}

	public function logWebhookDeleted(
		int $actingUserId,
		int $webhookId,
		int $ownerUserId,
		array $scopes,
	): void
	{
		$this->log(
			self::AUDIT_WEBHOOK_DELETE,
			$webhookId,
			[
				'actingUserId' => $actingUserId,
				'ownerUserId' => $ownerUserId,
				'scopes' => $scopes,
			],
		);
	}

	public function logWebhookOwnerChanged(
		int $actingUserId,
		int $fromUserId,
		int $toUserId,
		array $webhookIds,
	): void
	{
		$this->log(
			self::AUDIT_WEBHOOK_OWNER_CHANGE,
			$fromUserId,
			[
				'actingUserId' => $actingUserId,
				'fromUserId' => $fromUserId,
				'toUserId' => $toUserId,
				'webhookIds' => $webhookIds,
			],
		);
	}

	public function logAppInstalled(
		int $actingUserId,
		int $appId,
		string $clientId,
		array $scopes,
		string $installType,
	): void
	{
		$this->log(
			self::AUDIT_APP_INSTALL,
			$appId,
			[
				'actingUserId' => $actingUserId,
				'clientId' => $clientId,
				'scopes' => $scopes,
				'installType' => $installType,
			],
		);
	}

	public function logAppUninstalled(
		int $actingUserId,
		int $appId,
		string $clientId,
	): void
	{
		$this->log(
			self::AUDIT_APP_UNINSTALL,
			$appId,
			[
				'actingUserId' => $actingUserId,
				'clientId' => $clientId,
			],
		);
	}

	public function logAppAccessChanged(
		int $actingUserId,
		int $appId,
		string $clientId,
		string $action,
		array $previousAccessCodes,
		array $newAccessCodes,
	): void
	{
		$this->log(
			self::AUDIT_APP_ACCESS_CHANGE,
			$appId,
			[
				'actingUserId' => $actingUserId,
				'clientId' => $clientId,
				'action' => $action,
				'previousAccessCodes' => $previousAccessCodes,
				'newAccessCodes' => $newAccessCodes,
			],
		);
	}

	public function logScopeRequested(
		int $appId,
		int $requestId,
		array $scopes,
		string $comment,
	): void
	{
		$this->log(
			self::AUDIT_SCOPE_REQUEST,
			$requestId,
			[
				'appId' => $appId,
				'scopes' => $scopes,
				'comment' => $comment,
			],
		);
	}

	public function logScopeApproved(
		int $actingUserId,
		int $appId,
		int $requestId,
		array $scopes,
		string $comment = '',
	): void
	{
		$this->log(
			self::AUDIT_SCOPE_APPROVE,
			$requestId,
			[
				'actingUserId' => $actingUserId,
				'appId' => $appId,
				'scopes' => $scopes,
				'comment' => $comment,
			],
		);
	}

	public function logScopeRejected(
		int $actingUserId,
		int $appId,
		int $requestId,
		array $scopes,
		string $comment,
	): void
	{
		$this->log(
			self::AUDIT_SCOPE_REJECT,
			$requestId,
			[
				'actingUserId' => $actingUserId,
				'appId' => $appId,
				'scopes' => $scopes,
				'comment' => $comment,
			],
		);
	}

	public function logAccessPolicyChanged(
		EntityType $entityType,
		PermissionType $permission,
		array $previousAccessCodes,
		array $newAccessCodes,
		?int $actingUserId = null,
	): void
	{
		$this->log(
			self::AUDIT_ACCESS_POLICY_CHANGE,
			$entityType->value,
			[
				'actingUserId' => $actingUserId ?? $this->resolveActingUserId(),
				'entityType' => $entityType->value,
				'permission' => $permission->value,
				'previousAccessCodes' => $previousAccessCodes,
				'newAccessCodes' => $newAccessCodes,
			],
		);
	}

	public function logSystemUserActivated(
		int $systemUserId,
		int $resourceId,
		string $resourceType,
		?int $originalUserId = null,
	): void
	{
		$this->log(
			self::AUDIT_SYSTEM_USER_ACTIVATE,
			$systemUserId,
			[
				'systemUserId' => $systemUserId,
				'resourceId' => $resourceId,
				'resourceType' => $resourceType,
				'originalUserId' => $originalUserId,
			],
		);
	}

	public function logSystemUserDeactivated(
		int $systemUserId,
		int $resourceId,
		string $resourceType,
	): void
	{
		$this->log(
			self::AUDIT_SYSTEM_USER_DEACTIVATE,
			$systemUserId,
			[
				'systemUserId' => $systemUserId,
				'resourceId' => $resourceId,
				'resourceType' => $resourceType,
			],
		);
	}

	private function log(string $auditTypeId, int|string $itemId, array $context): void
	{
		if (!isset($context['actingUserId']) || (int)$context['actingUserId'] <= 0)
		{
			$context['actingUserId'] = $this->resolveActingUserId();
		}

		\CEventLog::Log(
			\CEventLog::SEVERITY_SECURITY,
			$auditTypeId,
			'rest',
			$itemId,
			Json::encode($context),
		);
	}


	private function resolveActingUserId(): int
	{
		$userId = (int)CurrentUser::get()->getId();
		if ($userId > 0)
		{
			return $userId;
		}

		global $USER;
		if (is_object($USER) && $USER->IsAuthorized())
		{
			return (int)$USER->GetID();
		}

		return 0;
	}
}
