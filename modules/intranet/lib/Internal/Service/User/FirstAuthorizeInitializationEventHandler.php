<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\User;

class FirstAuthorizeInitializationEventHandler
{
	private ?FirstAuthorizeInitializationPolicy $policy;
	private ?FirstAuthorizeInitializationService $service;

	public function __construct(
		?FirstAuthorizeInitializationPolicy $policy = null,
		?FirstAuthorizeInitializationService $service = null,
	)
	{
		$this->policy = $policy;
		$this->service = $service;
	}

	public function handle(
		array $eventData,
		bool $isCloud,
		bool $isVirtualSession,
	): void
	{
		$userFields = $eventData['user_fields'] ?? [];
		$userId = (int)($userFields['ID'] ?? 0);

		if ($userId <= 0)
		{
			return;
		}

		if (!$this->getPolicy()->shouldInitialize(
			isCloud: $isCloud,
			isVirtualSession: $isVirtualSession,
			isUpdate: (bool)($eventData['update'] ?? false),
			hasLastLogin: array_key_exists('LAST_LOGIN', $userFields),
			lastLogin: $userFields['LAST_LOGIN'] ?? null,
		))
		{
			return;
		}

		$this->getService()->initialize($userId);
	}

	private function getPolicy(): FirstAuthorizeInitializationPolicy
	{
		return $this->policy ??= new FirstAuthorizeInitializationPolicy();
	}

	private function getService(): FirstAuthorizeInitializationService
	{
		return $this->service ??= new FirstAuthorizeInitializationService();
	}
}
