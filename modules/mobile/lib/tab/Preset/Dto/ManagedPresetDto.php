<?php

namespace Bitrix\Mobile\Tab\Preset\Dto;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;

final class ManagedPresetDto implements \JsonSerializable
{
	public function __construct(
		public readonly ?int $id,
		public readonly int $ownerId,
		public readonly string $title,
		public readonly array $config,
		public readonly bool $isDeleted = false,
		public readonly ?DateTime $createdAt = null,
		public readonly ?DateTime $updatedAt = null,
	)
	{
	}

	public static function fromRow(array $row): self
	{
		return new self(
			id: isset($row['ID']) ? (int)$row['ID'] : null,
			ownerId: (int)$row['OWNER_ID'],
			title: (string)$row['TITLE'],
			config: self::extractConfig($row),
			isDeleted: ($row['IS_DELETED'] ?? 'N') === 'Y',
			createdAt: $row['CREATED_AT'] ?? null,
			updatedAt: $row['UPDATED_AT'] ?? null,
		);
	}

	private static function extractConfig(array $row): array
	{
		if (is_array($row['CONFIG'] ?? null))
		{
			return $row['CONFIG'];
		}

		if (!is_string($row['CONFIG_JSON'] ?? null) || $row['CONFIG_JSON'] === '')
		{
			return [];
		}

		try
		{
			$config = Json::decode($row['CONFIG_JSON']);
		}
		catch (\Throwable)
		{
			return [];
		}

		return is_array($config) ? $config : [];
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'ownerId' => $this->ownerId,
			'title' => $this->title,
			'config' => $this->config,
			'isDeleted' => $this->isDeleted,
		];
	}
}
