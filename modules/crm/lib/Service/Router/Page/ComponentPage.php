<?php

namespace Bitrix\Crm\Service\Router\Page;

use Bitrix\Crm\Service\Router\Component\Component;
use Bitrix\Crm\Service\Router\Contract\Page;

final class ComponentPage implements Page
{
	public function __construct(
		private readonly Component $component,
	)
	{
	}

	public function render(?\CBitrixComponent $parentComponent = null): void
	{
		$this->component->parent = $parentComponent;
		$this->component->render();
	}

	public function title(): ?string
	{
		return null;
	}

	public function canUseFavoriteStar(): bool
	{
		return true;
	}
}
