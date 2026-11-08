<?php

declare(strict_types=1);

namespace Bitrix\UI\AccessRights\V2\Control\Value;

use Bitrix\Main\Type\Contract\Arrayable;

/**
 * Describes a server-side source of multivariables values: the dialog takes them from
 * a `ui.entity-selector` entity instead of the static variables list.
 */
class VariablesSource implements Arrayable
{
	public function __construct(
		protected readonly string $entityId,
		protected readonly bool $dynamicLoad = true,
		protected readonly bool $dynamicSearch = true,
		protected readonly ?string $dynamicSearchMatchMode = null,
		protected readonly array $providerOptions = [],
		protected readonly bool $fillRecentItems = false,
		protected readonly ?string $itemEntityId = null,
	)
	{
	}

	public function entityId(): string
	{
		return $this->entityId;
	}

	public function dynamicLoad(): bool
	{
		return $this->dynamicLoad;
	}

	public function dynamicSearch(): bool
	{
		return $this->dynamicSearch;
	}

	public function dynamicSearchMatchMode(): ?string
	{
		return $this->dynamicSearchMatchMode;
	}

	public function providerOptions(): array
	{
		return $this->providerOptions;
	}

	public function fillRecentItems(): bool
	{
		return $this->fillRecentItems;
	}

	public function itemEntityId(): string
	{
		return $this->itemEntityId ?? $this->entityId;
	}

	public function toArray(): array
	{
		return [
			'entityId' => $this->entityId(),
			'dynamicLoad' => $this->dynamicLoad(),
			'dynamicSearch' => $this->dynamicSearch(),
			'dynamicSearchMatchMode' => $this->dynamicSearchMatchMode(),
			'providerOptions' => $this->providerOptions(),
			'fillRecentItems' => $this->fillRecentItems(),
			'itemEntityId' => $this->itemEntityId(),
		];
	}
}
