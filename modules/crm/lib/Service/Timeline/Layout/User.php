<?php

namespace Bitrix\Crm\Service\Timeline\Layout;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\Engine\CurrentUser;

class User extends Base
{
	protected int $id;
	protected string $title;
	protected string $detailUrl;
	protected ?string $imageUrl;

	public function __construct(int $id, string $title, string $detailUrl, ?string $imageUrl = null)
	{
		$this->id = $id;
		$this->title = $title;
		$this->detailUrl = $detailUrl;
		$this->imageUrl = $imageUrl;
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function getDetailUrl(): string
	{
		return $this->detailUrl;
	}

	public function getImageUrl(): ?string
	{
		return $this->imageUrl;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->getId(),
			'title' => $this->getTitle(),
			'detailUrl' => $this->getDetailUrl(),
			'imageUrl' => $this->getImageUrl(),
		];
	}

	public static function current(): self
	{
		static $user;

		if (!$user)
		{
			$user = Container::getInstance()
				->getUserBroker()
				->getById(CurrentUser::get()->getId());
		}

		return new self(
			(int)$user['ID'],
			$user['FORMATTED_NAME'] ?? '',
			$user['SHOW_URL'] ?? '',
			$user['PHOTO_URL'] ?? '',
		);
	}

	public static function createFromArray(array $user): self
	{
		return new self(
			(int)$user['ID'],
			$user['FORMATTED_NAME'] ?? '',
			$user['SHOW_URL'] ?? '',
			$user['PHOTO_URL'] ?? '',
		);
	}
}
