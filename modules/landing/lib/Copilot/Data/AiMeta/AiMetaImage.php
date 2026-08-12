<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Data\AiMeta;

final class AiMetaImage
{
	public function __construct(
		private string $nodeCode = '',
		private string $prompt = '',
		private string $alt = '',
		private string $aspect = 'square',
		private bool $shouldGenerate = false,
	)
	{
	}

	public static function fromArray(array $data): self
	{
		return new self(
			nodeCode: trim((string)($data['nodeCode'] ?? '')),
			prompt: trim((string)($data['prompt'] ?? '')),
			alt: trim((string)($data['alt'] ?? '')),
			aspect: self::normalizeAspect((string)($data['aspect'] ?? $data['format'] ?? 'square')),
			shouldGenerate: !empty($data['shouldGenerate']),
		);
	}

	public function toArray(): array
	{
		return [
			'nodeCode' => $this->nodeCode,
			'prompt' => $this->prompt,
			'alt' => $this->alt,
			'aspect' => $this->aspect,
			'shouldGenerate' => $this->shouldGenerate,
		];
	}

	public function getNodeCode(): string
	{
		return $this->nodeCode;
	}

	public function getPrompt(): string
	{
		return $this->prompt;
	}

	private static function normalizeAspect(string $aspect): string
	{
		$aspect = trim($aspect);

		return $aspect !== '' ? $aspect : 'square';
	}
}
