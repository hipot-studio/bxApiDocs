<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\Invitation;

use Bitrix\Intranet\User;

final readonly class InvitationCounterResyncTarget
{
	public function __construct(
		public ?User $originator,
	)
	{
	}
}
