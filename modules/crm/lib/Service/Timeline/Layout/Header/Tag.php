<?php

namespace Bitrix\Crm\Service\Timeline\Layout\Header;

use Bitrix\Crm\Service\Timeline\Layout\Button;

class Tag extends Button
{
	public const TYPE_SUCCESS = 'success';
	public const TYPE_FAILURE = 'failure';
	public const TYPE_WARNING = 'warning';
	public const TYPE_PRIMARY = 'primary';
	public const TYPE_SECONDARY = 'secondary';
	public const TYPE_LAVENDER = 'lavender';

	protected string $type;
	protected string $hint = '';
	protected string $tagId = '';

	public function __construct(string $title, string $type, string $tagId = '')
	{
		parent::__construct($title);

		$this->type = $type;
		$this->tagId = $tagId;
	}

	public function getType(): string
	{
		return $this->type;
	}

	public function setType(string $type): self
	{
		$this->type = $type;

		return $this;
	}

	public function getHint(): string
	{
		return $this->hint;
	}

	public function setHint(string $hint): self
	{
		$this->hint = $hint;

		return $this;
	}

	public function getTagId(): string
	{
		return $this->tagId;
	}

	public function setTagId(string $id): self
	{
		$this->tagId = $id;

		return $this;
	}

	public function toArray(): array
	{
		return array_merge(
			parent::toArray(),
			[
				'tagId' => $this->getTagId(),
				'type' => $this->getType(),
				'hint' => $this->getHint(),
			]
		);
	}
}
