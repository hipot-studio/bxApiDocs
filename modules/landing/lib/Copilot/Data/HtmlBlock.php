<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Data;

use Bitrix\Landing\Copilot\Data\AiMeta\AiMeta;

final class HtmlBlock
{
	private array $data = [];

	public static function fromArray(array $data): self
	{
		$block = new self();
		$block->data = $data;

		return $block;
	}

	public function toArray(): array
	{
		return $this->data;
	}

	public function getHtml(): string
	{
		return (string)($this->data['html'] ?? '');
	}

	public function setHtml(string $html): void
	{
		$this->data['html'] = $html;
	}

	public function getAiMeta(): AiMeta
	{
		return AiMeta::fromArray($this->data['aiMeta'] ?? null);
	}

	public function setAiMeta(AiMeta $aiMeta): void
	{
		$this->data['aiMeta'] = $aiMeta->toArray();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getSpec(): array
	{
		$spec = $this->data['spec'] ?? null;

		return is_array($spec) ? $spec : [];
	}

	/**
	 * @param array<string, mixed> $spec
	 */
	public function setSpec(array $spec): void
	{
		$this->data['spec'] = $spec;
	}

	public function getPosition(): int
	{
		return (int)($this->data['position'] ?? 0);
	}

	public function setPosition(int $position): void
	{
		$this->data['position'] = $position;
	}

	public function getRepoId(): int
	{
		return max(0, (int)($this->data['repoId'] ?? 0));
	}

	public function setRepoId(int $repoId): void
	{
		$this->data['repoId'] = max(0, $repoId);
	}

	public function getBlockId(): int
	{
		return max(0, (int)($this->data['blockId'] ?? 0));
	}

	public function setBlockId(int $blockId): void
	{
		$this->data['blockId'] = max(0, $blockId);
	}
}
