<?php

namespace Bitrix\Crm\Service\Router\Page;

use Bitrix\Crm\Service\Router\AbstractPage;
use Bitrix\Crm\Service\Router\Component\Component;
use Bitrix\Crm\Service\Router\Contract;
use Bitrix\Crm\Service\Router\Contract\Page;
use Bitrix\Crm\Service\Router\Enum\Scope;
use Bitrix\Crm\Service\Router\Route;
use Bitrix\Main\HttpRequest;

final class UserDefinedPage implements Contract\Page\UserDefinedPage
{
	private array $parameters = [];

	public function __construct(
		private readonly string $componentName,
		private readonly string $url,
	)
	{
	}

	public function title(): ?string
	{
		return null;
	}

	public function canUseFavoriteStar(): ?bool
	{
		return null;
	}

	public function render(?\CBitrixComponent $parentComponent = null): void
	{
		$component = new Component(
			name: $this->componentName,
			parameters: $this->parameters,
			parent: $parentComponent,
		);

		$component->render();
	}

	public function route(): Route
	{
		return (new Route($this->url))
			->setRelatedComponent($this->componentName)
		;
	}

	public function setParameters(array $parameters = []): self
	{
		$this->parameters = $parameters;

		return $this;
	}
}
