<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\UI\ConnectionsSlider;

abstract class Page implements \JsonSerializable
{
	/**
	 * @param Section[] $allSections
	 *
	 * @return self|null
	 */
	abstract public static function create(array $allSections): ?self;

	abstract public function getTitle(): string;

	/**
	 * @return Section[]
	 */
	abstract public function getSections(): array;

	abstract public function getId(): string;

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->getId(),
			'title' => $this->getTitle(),
			'sections' => $this->getSections(),
		];
	}
}
