<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Data\WorkflowTemplateHistoryService;

use Bitrix\Bizproc\Api\Enum\Template\TemplateChangeElementType;
use Bitrix\Bizproc\Api\Enum\Template\TemplateChangeKind;

final class TemplateChangeEntry
{
	public const TEXT_LENGTH_LIMIT = 60;

	public readonly ?string $elementTitle;
	public readonly ?string $oldValue;
	public readonly ?string $newValue;
	public readonly ?string $settingTitle;

	/**
	 * Texts of a line are bounded here, at the only door of the journal: a title, a value and a name of a
	 * setting are written by a person and grow as long as the template lets them. The id of an element and
	 * the key of a setting are left whole: they point at a part of the template rather than read as text,
	 * and a cut one points at nothing.
	 *
	 * @param string|null $settingKey place of the setting in its block; a block and an activity its rules
	 * run may name two settings alike, and only the key tells one line of the journal from the other
	 */
	public function __construct(
		public readonly TemplateChangeElementType $elementType,
		public readonly ?string $elementId,
		?string $elementTitle,
		public readonly TemplateChangeKind $kind,
		?string $oldValue = null,
		?string $newValue = null,
		?string $settingTitle = null,
		public readonly ?string $settingKey = null,
		public readonly ?int $changedTimestamp = null,
	)
	{
		$this->elementTitle = self::boundText($elementTitle);
		$this->oldValue = self::boundText($oldValue);
		$this->newValue = self::boundText($newValue);
		$this->settingTitle = self::boundText($settingTitle);
	}

	private static function boundText(?string $text): ?string
	{
		return $text === null ? null : TruncateText($text, self::TEXT_LENGTH_LIMIT);
	}

	public function toArray(): array
	{
		return [
			'elementType' => $this->elementType->value,
			'elementId' => $this->elementId,
			'elementTitle' => $this->elementTitle,
			'kind' => $this->kind->value,
			'oldValue' => $this->oldValue,
			'newValue' => $this->newValue,
			'settingTitle' => $this->settingTitle,
			'settingKey' => $this->settingKey,
			'changedTimestamp' => $this->changedTimestamp,
		];
	}

	/**
	 * @return self|null null for a stored line the current code cannot read anymore
	 */
	public static function fromArray(array $entry): ?self
	{
		$elementType = self::readString($entry, 'elementType');
		$kind = self::readString($entry, 'kind');

		$elementTypeCase = $elementType === null ? null : TemplateChangeElementType::tryFrom($elementType);
		$kindCase = $kind === null ? null : TemplateChangeKind::tryFrom($kind);

		if ($elementTypeCase === null || $kindCase === null)
		{
			return null;
		}

		return new self(
			$elementTypeCase,
			self::readString($entry, 'elementId'),
			self::readString($entry, 'elementTitle'),
			$kindCase,
			self::readString($entry, 'oldValue'),
			self::readString($entry, 'newValue'),
			self::readString($entry, 'settingTitle'),
			self::readString($entry, 'settingKey'),
			self::readInt($entry, 'changedTimestamp'),
		);
	}

	private static function readString(array $entry, string $key): ?string
	{
		$value = $entry[$key] ?? null;

		return is_string($value) ? $value : null;
	}

	private static function readInt(array $entry, string $key): ?int
	{
		$value = $entry[$key] ?? null;

		return is_numeric($value) ? (int)$value : null;
	}

	public function withTimestamp(int $timestamp): self
	{
		if ($this->changedTimestamp !== null)
		{
			return $this;
		}

		return new self(
			$this->elementType,
			$this->elementId,
			$this->elementTitle,
			$this->kind,
			$this->oldValue,
			$this->newValue,
			$this->settingTitle,
			$this->settingKey,
			$timestamp,
		);
	}
}
