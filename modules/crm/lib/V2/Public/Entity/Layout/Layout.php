<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Layout;

final class Layout
{
	public function __construct(
		private readonly LayoutId $id,
		private readonly LayoutConfiguration $configuration,
		private readonly ?LayoutName $name = null,
		private readonly ?LayoutAudience $audience = null,
	)
	{
	}

	public function getId(): LayoutId
	{
		return $this->id;
	}

	public function getConfiguration(): LayoutConfiguration
	{
		return $this->configuration;
	}

	public function getName(): ?LayoutName
	{
		return $this->name;
	}

	public function getAudience(): ?LayoutAudience
	{
		return $this->audience;
	}
}
