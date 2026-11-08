<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Rest;

use Bitrix\Main\Loader;
use Bitrix\Rest\Public\Provider\EmbeddingProvider;

class EmbeddingProviderResolver
{
	private ?bool $moduleIncluded = null;

	public function __construct(private ?EmbeddingProvider $provider = null) {}

	public function isAvailable(): bool
	{
		return $this->provider !== null || $this->isModuleIncluded();
	}

	public function resolve(): ?EmbeddingProvider
	{
		if ($this->provider === null && $this->isModuleIncluded())
		{
			$this->provider = new EmbeddingProvider();
		}

		return $this->provider;
	}

	private function isModuleIncluded(): bool
	{
		if ($this->moduleIncluded === null)
		{
			$this->moduleIncluded = Loader::includeModule('rest');
		}

		return $this->moduleIncluded;
	}
}
