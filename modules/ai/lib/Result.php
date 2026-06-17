<?php

namespace Bitrix\AI;

use Bitrix\AI\Engine\ResponseFormat;
use Bitrix\AI\Services\MarkdownToHtmlTranslationService;
use Bitrix\AI\Services\MarkdownToPlainTextTranslationService;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\AI\Services\MarkdownToBBCodeTranslationService;

class Result
{
	public function __construct(
		private mixed $rawData,
		private ?string $prettifyData,
		private bool $cached = false,
		private ?array $jsonData = [],
	)
	{
	}

	public function getData(ResponseFormat $responseFormat): mixed
	{
		return match ($responseFormat)
		{
			ResponseFormat::JSON => $this->getJsonData(),
			ResponseFormat::HTML => $this->getHtmlData(),
			ResponseFormat::BBCODE => $this->getBBCodeData(),
			ResponseFormat::PLAINTEXT => $this->getPlainTextData(),
			default => $this->getPrettifiedData(),
		};
	}

	public function getRawData(): mixed
	{
		return $this->rawData;
	}

	public function getPrettifiedData(): ?string
	{
		return $this->prettifyData;
	}

	public function getBBCodeData(): ?string
	{
		if ($this->isNullPrettifyData())
		{
			return null;
		}

		$service = ServiceLocator::getInstance()->get(MarkdownToBBCodeTranslationService::class);

		return $service->convert($this->prettifyData);
	}

	public function getPlainTextData(): ?string
	{
		if ($this->isNullPrettifyData())
		{
			return null;
		}

		$service = ServiceLocator::getInstance()->get(MarkdownToPlainTextTranslationService::class);

		return $service->convert($this->prettifyData);
	}

	public function getHtmlData(): ?string
	{
		if ($this->isNullPrettifyData())
		{
			return null;
		}

		$service = ServiceLocator::getInstance()->get(MarkdownToHtmlTranslationService::class);

		return $service->convert($this->prettifyData);
	}

	public function getJsonData(): ?array
	{
		return $this->jsonData;
	}

	public function isCached(): bool
	{
		return $this->cached;
	}

	private function isNullPrettifyData(): bool
	{
		return $this->prettifyData === null;
	}
}
