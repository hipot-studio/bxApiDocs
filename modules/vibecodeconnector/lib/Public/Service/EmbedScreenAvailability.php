<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Service;

use Bitrix\Vibecodeconnector\Internal\Service\Embed\EmbedCreateGuard;
use Bitrix\Vibecodeconnector\Internal\Service\Embed\EmbedView;
use Bitrix\Vibecodeconnector\Internal\Service\Embed\EmbedViewSwitch;

final class EmbedScreenAvailability
{
	public function __construct(
		private readonly EmbedCreateGuard $guard = new EmbedCreateGuard(),
		private readonly EmbedViewSwitch $viewSwitch = new EmbedViewSwitch(),
	) {
	}

	public function isCoworkAdminAvailable(int $userId): bool
	{
		return $this->viewSwitch->isEnabled(EmbedView::CoworkAdmin)
			&& $this->guard->isAllowed(EmbedView::CoworkAdmin, $userId)
		;
	}
}
