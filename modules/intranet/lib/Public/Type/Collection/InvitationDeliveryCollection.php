<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Type\Collection;

use Bitrix\Intranet\Public\Type\BaseInvitation;

final class InvitationDeliveryCollection extends InvitationCollection
{
	/** @var list<string> */
	private array $clientIds = [];

	public function addWithClientId(BaseInvitation $invitation, string $clientId): void
	{
		parent::add($invitation);
		$this->clientIds[] = $clientId;
	}

	/** @return list<string> */
	public function getClientIds(): array
	{
		return $this->clientIds;
	}
}
