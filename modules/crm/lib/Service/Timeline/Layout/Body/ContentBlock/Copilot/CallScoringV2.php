<?php

namespace Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\Copilot;

use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock;
use Bitrix\Crm\Service\Timeline\Layout\Mixin;

class CallScoringV2 extends ContentBlock
{
	use Mixin\Actionable;

	private ?string $title = null;
	protected ?int $score = null;
	protected ?int $scoreLowBorder = null;
	protected ?int $scoreHighBorder = null;
	protected ?string $scoreDescription = null;

	public function getRendererName(): string
	{
		return 'CallScoringV2';
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

	public function getScore(): ?int
	{
		return $this->score;
	}

	public function setScore(?int $score): self
	{
		$this->score = $score;

		return $this;
	}

	public function getScoreLowBorder(): ?int
	{
		return $this->scoreLowBorder;
	}

	public function setScoreLowBorder(?int $scoreLowBorder): self
	{
		$this->scoreLowBorder = $scoreLowBorder;

		return $this;
	}

	public function getScoreHighBorder(): ?int
	{
		return $this->scoreHighBorder;
	}

	public function setScoreHighBorder(?int $scoreHighBorder): self
	{
		$this->scoreHighBorder = $scoreHighBorder;

		return $this;
	}

	public function getScoreDescription(): ?string
	{
		return $this->scoreDescription;
	}

	public function setScoreDescription(?string $scoreDescription): self
	{
		$this->scoreDescription = $scoreDescription;

		return $this;
	}

	protected function getProperties(): array
	{
		return [
			'scriptTitle' => $this->getTitle(),
			'score' => $this->getScore(),
			'scoreLowBorder' => $this->getScoreLowBorder(),
			'scoreHighBorder' => $this->getScoreHighBorder(),
			'scoreDescription' => $this->getScoreDescription(),
			'action' => $this->getAction(),
		];
	}
}
