<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Public\Providers;

class PlainText extends Provider implements TextProviderInterface
{
	private string $text;

	public function __construct(string $text)
	{
		$this->text = $text;
	}

	public static function createFromData(array $data): ?static
	{
		if (!array_key_exists('text', $data))
		{
			return null;
		}

		return new self((string)$data['text']);
	}

	public function getData(): ?array
	{
		return ['text' => $this->text];
	}

	public function getText(): string
	{
		return $this->text;
	}
}
