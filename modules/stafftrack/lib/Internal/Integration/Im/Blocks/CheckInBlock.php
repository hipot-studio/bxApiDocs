<?php

namespace Bitrix\StaffTrack\Internal\Integration\Im\Blocks;

final readonly class CheckInBlock
{
	public const TITLE_SIZE_MEDIUM = 'medium';
	public const SPACE_DIVIDER_SIZE_SMALL = 'small';

	/**
	 * @param array<string, mixed> $data
	 */
	private function __construct(
		public CheckInBlockType $type,
		public array $data,
	)
	{
	}

	public static function title(string $text, string $size): self
	{
		return new self(CheckInBlockType::Title, [
			'text' => $text,
			'size' => $size,
		]);
	}

	public static function text(string $text): self
	{
		return new self(CheckInBlockType::Text, [
			'text' => $text,
		]);
	}

	public static function spaceDivider(string $size): self
	{
		return new self(CheckInBlockType::SpaceDivider, [
			'size' => $size,
		]);
	}

	/**
	 * @param list<int> $fileIds
	 */
	public static function gallery(array $fileIds): self
	{
		return new self(CheckInBlockType::Gallery, [
			'fileIds' => $fileIds,
		]);
	}

	/**
	 * @param list<int> $fileIds
	 */
	public static function file(array $fileIds): self
	{
		return new self(CheckInBlockType::File, [
			'fileIds' => $fileIds,
		]);
	}

	public static function lineDivider(): self
	{
		return new self(CheckInBlockType::LineDivider, []);
	}

	public static function map(string $imageUrl, ?string $text): self
	{
		return new self(CheckInBlockType::Map, [
			'imageUrl' => $imageUrl,
			'text' => $text,
		]);
	}
}
