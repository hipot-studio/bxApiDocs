<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Grid\StorageList\Filter;

use Bitrix\Bizproc\Internal\Model\StorageTypeTable;

class StorageListFilter
{
	private const FIELD_TITLE = 'TITLE';
	private const FIELD_CODE = 'CODE';
	private const FIELD_DESCRIPTION = 'DESCRIPTION';

	public function __construct(private readonly string $id)
	{
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getOptions(string $gridId): array
	{
		return [
			'FILTER_ID' => $this->id,
			'GRID_ID' => $gridId,
			'FILTER' => $this->getFields(),
			'FILTER_PRESETS' => [],
			'ENABLE_LIVE_SEARCH' => true,
			'ENABLE_LABEL' => true,
		];
	}

	public function getFields(): array
	{
		$entity = StorageTypeTable::getEntity();

		return [
			self::FIELD_TITLE => [
				'id' => self::FIELD_TITLE,
				'name' => $entity->getField(self::FIELD_TITLE)->getTitle(),
				'type' => 'string',
				'default' => true,
			],
			self::FIELD_CODE => [
				'id' => self::FIELD_CODE,
				'name' => $entity->getField(self::FIELD_CODE)->getTitle(),
				'type' => 'string',
				'default' => true,
			],
			self::FIELD_DESCRIPTION => [
				'id' => self::FIELD_DESCRIPTION,
				'name' => $entity->getField(self::FIELD_DESCRIPTION)->getTitle(),
				'type' => 'string',
				'default' => true,
			],
		];
	}
}
