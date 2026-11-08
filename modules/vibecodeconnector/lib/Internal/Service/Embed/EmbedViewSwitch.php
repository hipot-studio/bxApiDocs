<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Embed;

final class EmbedViewSwitch
{
	public function __construct(
		private readonly EmbedCreateSettings $createSettings = new EmbedCreateSettings(),
		private readonly EmbedCoworkAdminSettings $coworkAdminSettings = new EmbedCoworkAdminSettings(),
	) {
	}

	public function isEnabled(EmbedView $view): bool
	{
		return $view === EmbedView::CoworkAdmin
			? $this->coworkAdminSettings->isEnabled()
			: $this->createSettings->isEnabled()
		;
	}
}
