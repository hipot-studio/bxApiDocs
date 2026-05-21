<?php

namespace Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock;

use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock;

class Audio extends ContentBlock
{
	public const TRANSCRIPTION_EMPTY = 'empty';
	public const TRANSCRIPTION_PENDING = 'pending';
	public const TRANSCRIPTION_SUCCESS = 'success';
	public const TRANSCRIPTION_FAILED = 'failed';

	protected int $id = 0;
	protected string $source = '';
	protected ?string $title = null;
	protected ?string $imageUrl = null;
	protected ?string $recordName = null;
	protected ?string $transcriptionState = null;

	public function getRendererName(): string
	{
		return 'TimelineAudio';
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function setId(int $id = 0): self
	{
		$this->id = $id;

		return $this;
	}

	public function getSource(): string
	{
		return $this->source;
	}

	public function setSource(string $source = ''): self
	{
		$this->source = $source;

		return $this;
	}

	public function getTitle(): ?string
	{
		return $this->title;
	}

	public function setTitle(?string $title): self
	{
		$this->title = $title;

		return $this;
	}

	public function getImageUrl(): ?string
	{
		return $this->imageUrl;
	}

	public function setImageUrl(?string $imageUrl): self
	{
		$this->imageUrl = $imageUrl;

		return $this;
	}

	public function getRecordName(): ?string
	{
		return $this->recordName;
	}

	public function setRecordName(?string $recordName): self
	{
		$this->recordName = $recordName;

		return $this;
	}

	public function getTranscriptionState(): ?string
	{
		return $this->transcriptionState;
	}

	public function setTranscriptionState(?string $transcriptionState): self
	{
		$this->transcriptionState = $transcriptionState;

		return $this;
	}

	protected function getProperties(): array
	{
		return [
			'id' => $this->getId(),
			'src' => $this->getSource(),
			'title' => $this->getTitle(),
			'imageUrl' => $this->getImageUrl(),
			'recordName' => $this->getRecordName(),
			'transcriptionState' => $this->getTranscriptionState(),
		];
	}
}
