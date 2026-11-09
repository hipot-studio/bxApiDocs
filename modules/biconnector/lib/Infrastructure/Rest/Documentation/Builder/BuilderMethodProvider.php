<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Documentation\Builder;

use Bitrix\Rest\V3\Documentation\MethodProvider;

abstract class BuilderMethodProvider extends MethodProvider
{
	protected const METHOD_URI = '';
	private array $operation;

	public function __construct(string $language)
	{
		parent::__construct($language);
		$this->operation = BuilderContractSchemas::forMethod(static::METHOD_URI);
	}

	protected function getTags(): array
	{
		return $this->operation['tags'];
	}

	protected function getRequestBody(): array
	{
		return $this->operation['requestBody'];
	}

	protected function getResponses(): array
	{
		return $this->operation['responses'];
	}

	protected function getTitle(): ?string
	{
		return $this->operation['summary'] ?? null;
	}

	protected function getDescription(): ?string
	{
		return $this->operation['description'] ?? null;
	}
}
