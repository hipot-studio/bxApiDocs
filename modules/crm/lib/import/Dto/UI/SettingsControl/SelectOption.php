<?php

namespace Bitrix\Crm\Import\Dto\UI\SettingsControl;

use Bitrix\Crm\Import\Contract\Enum\HasHintInterface;
use Bitrix\Crm\Import\Contract\Enum\HasTitleInterface;

final class SelectOption
{
	private ?string $hint = null;

	public function __construct(
		private readonly string|int $id,
		private readonly string $title,
	)
	{
	}

	public function getId(): string|int
	{
		return $this->id;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function getHint(): ?string
	{
		return $this->hint;
	}

	public function setHint(?string $hint): self
	{
		$this->hint = $hint;

		return $this;
	}

	/**
	 * @param class-string<HasTitleInterface|HasTitleInterface & HasHintInterface> $enum
	 * @return array
	 */
	public static function fromEnum(string $enum): array
	{
		return self::fromEnumCases($enum::cases());
	}

	/**
	 * @param (HasTitleInterface|(HasTitleInterface & HasHintInterface))[] $cases
	 * @return array
	 */
	public static function fromEnumCases(array $cases): array
	{
		$selectOptions = [];
		foreach ($cases as $case)
		{
			$option = new SelectOption($case->value, $case->getTitle());

			if ($case instanceof HasHintInterface)
			{
				$option->setHint($case->getHint());
			}

			$selectOptions[] = $option;
		}

		return $selectOptions;
	}

	public static function fromStatusList(array $statusList): array
	{
		$selectOptions = [];
		foreach ($statusList as $id => $title)
		{
			$selectOptions[] = new SelectOption($id, $title);
		}

		return $selectOptions;
	}

	public function toArray(): array
	{
		return [
			'title' => $this->title,
			'value' => $this->id,
			'hint' => $this->hint,
		];
	}
}
