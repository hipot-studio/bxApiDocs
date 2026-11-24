<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Footer;

use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons\Type;

class FooterElement implements \JsonSerializable
{
	protected string $id;
	protected string $kind;
	protected string $type;
	protected string $title;
	protected string $design;
	protected ?string $url;
	protected ?string $actionId;
	protected ?array $actionParams;
	protected ?array $forUsers;
	protected ?bool $showTitle;
	protected ?string $icon;
	protected ?string $size;
	protected int $linePosition;

	protected function __construct(array $elementData)
	{
		$this->id = (string)($elementData['id'] ?? '');
		$this->kind = $elementData['kind'];
		$this->type = $elementData['type'];
		$this->title = $elementData['title'];
		$this->design = $elementData['design'];
		$this->url = $elementData['url'] ?? null;
		$this->actionId = $elementData['actionId'] ?? null;
		$this->actionParams = $elementData['actionParams'] ?? null;
		$this->forUsers = $elementData['forUsers'] ?? null;
		$this->showTitle = $elementData['showTitle'] ?? null;
		$this->icon = $elementData['icon'] ?? null;
		$this->size = $elementData['size'] ?? null;
		$this->linePosition = $elementData['linePosition'];
	}

	public static function create(array $elementData): ?self
	{
		if (Type::tryFrom($elementData['type'] ?? '') === null)
		{
			return null;
		}

		return new self($elementData);
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}

	public function toArray(): array
	{
		$element = [
			'id' => $this->id,
			'kind' => $this->kind,
			'type' => $this->type,
			'title' => $this->title,
			'design' => $this->design,
		];

		if ($this->url !== null)
		{
			$element['url'] = $this->url;
		}

		if ($this->actionId !== null)
		{
			$element['actionId'] = $this->actionId;
			$element['actionParams'] = $this->actionParams;
		}

		if ($this->forUsers !== null)
		{
			$element['forUsers'] = $this->forUsers;
		}

		if ($this->showTitle !== null)
		{
			$element['showTitle'] = $this->showTitle;
		}

		if ($this->icon !== null)
		{
			$element['icon'] = $this->icon;
		}

		if ($this->size !== null)
		{
			$element['size'] = $this->size;
		}

		return $element;
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getType(): string
	{
		return $this->type;
	}

	/**
	 * No forUsers field means the element is open to everyone, a list means it is open to the users
	 * it names, and an empty list means it is open to no one.
	 */
	public function isAllowedFor(int $userId): bool
	{
		if ($this->forUsers === null)
		{
			return true;
		}

		return in_array($userId, $this->forUsers, true);
	}

	/**
	 * The same rule read off raw element data: the field being there is the address, whatever the
	 * list holds.
	 */
	public static function isAddressedToSomeone(array $elementData): bool
	{
		return array_key_exists('forUsers', $elementData);
	}

	public function getLinePosition(): int
	{
		return $this->linePosition;
	}
}
