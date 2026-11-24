<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Permission\Policy;

use Bitrix\Im\V2\Permission\Action;

class ActionAccessPolicyRegistry
{
	/**
	 * @var array<string, ActionAccessPolicy>
	 */
	private array $policies;

	public function __construct(
		private readonly DefaultPolicy $defaultPolicy,
		ReadChatPolicy $readChatPolicy,
		UnpinChatPolicy $unpinChatPolicy,
		ThreadActionPolicy $threadActionPolicy,
	)
	{
		$this->policies = [
			Action::UnpinChat->value => $unpinChatPolicy,
			Action::ReadChat->value => $readChatPolicy,
			Action::CreateThread->value => $threadActionPolicy,
			Action::PostInThread->value => $threadActionPolicy,
		];
	}

	public function getPolicy(Action $action): ActionAccessPolicy
	{
		return $this->policies[$action->value] ?? $this->defaultPolicy;
	}
}
