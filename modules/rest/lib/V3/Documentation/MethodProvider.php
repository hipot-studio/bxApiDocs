<?php

namespace Bitrix\Rest\V3\Documentation;


abstract class MethodProvider
{
	protected const DEPRECATED = false;

	abstract protected function getTags(): array;

	abstract protected function getRequestBody(): array;

	abstract protected function getResponses(): array;

	abstract protected function getTitle(): ?string;

	abstract protected function getDescription(): ?string;

	public function __construct(protected string $language)
	{
	}

	public function getDocumentation(): array
	{
		$result = [
			'tags' => $this->getTags(),
			'requestBody' => $this->getRequestBody(),
			'responses' => $this->getResponses(),
		];

		if ($this->getTitle() !== null)
		{
			$result['summary'] = $this->getTitle();
		}

		if ($this->getDescription() !== null)
		{
			$result['description'] = $this->getDescription();
		}

		if (static::DEPRECATED)
		{
			$result['deprecated'] = true;
		}

		return $result;
	}
}
