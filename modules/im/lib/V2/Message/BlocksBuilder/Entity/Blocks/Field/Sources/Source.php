<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Sources;

class Source implements \JsonSerializable
{
	protected string $url;
	protected array $metaData = [];
	protected int $id;

	private function __construct(array $elementData)
	{
		$this->url = $elementData['url'];
		$this->metaData = $elementData['metaData'];
		$this->id = $elementData['id'];
	}

	public static function create(array $elementData): self
	{
		return new self($elementData);
	}

	public function getPayloadText(): ?string
	{
		return null;
	}

	public function jsonSerialize(): array
	{
		return [
			'url' => $this->url,
			'metaData' => empty($this->metaData) ? null : $this->metaData,
		];
	}

	public function toArray(): array
	{
		return [
			'url' => $this->url,
			'metaData' => empty($this->metaData) ? null : $this->metaData,
		];
	}

	public function getId(): int
	{
		return $this->id;
	}
}
