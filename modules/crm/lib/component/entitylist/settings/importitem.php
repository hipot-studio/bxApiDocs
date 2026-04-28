<?php

namespace Bitrix\Crm\Component\EntityList\Settings;

use Bitrix\Crm\Entity\MessageBuilder\ImportSettingsItemBuilder;
use Bitrix\Crm\Import\Enum\Contact\Origin;
use Bitrix\Crm\Integration\Analytics\Builder\Import\ViewEvent;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\UI\Buttons\JsHandler;
use CCrmOwnerType;
use JsonSerializable;

final class ImportItem implements JsonSerializable, Arrayable
{
	private const ID = 'import-item';

	private ?int $categoryId = null;
	private ?Origin $origin = null;

	public function __construct(
		private readonly int $entityTypeId,
	)
	{
	}

	public function getId(): string
	{
		$parts = [
			$this->categoryId,
			$this->origin?->value,
		];

		$postfix = implode('_', $parts);

		return "import-item-{$this->entityTypeId}_{$postfix}";
	}

	public function setOrigin(?Origin $origin): self
	{
		$this->origin = $origin;

		return $this;
	}

	public function setCategoryId(?int $categoryId): self
	{
		$this->categoryId = $categoryId;

		return $this;
	}

	public function canShow(): bool
	{
		if (!Container::getInstance()->getUserPermissions()->entityType()->canImportItems($this->entityTypeId))
		{
			return false;
		}

		$availableEntityTypeIds = [
			CCrmOwnerType::Lead,
			CCrmOwnerType::Deal,
			CCrmOwnerType::Contact,
			CCrmOwnerType::Company,
			CCrmOwnerType::Quote,
			CCrmOwnerType::SmartInvoice,
		];

		if (in_array($this->entityTypeId, $availableEntityTypeIds, true))
		{
			return true;
		}

		return CCrmOwnerType::isPossibleDynamicTypeId($this->entityTypeId);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->getId(),
			'text' => $this->getCaption(),
			'title' => $this->getDescription(),
			'href' => $this->getUrl(),
			'onclick' => new JsHandler('BX.Crm.Router.Instance.closeSettingsMenu'),
		];
	}

	public function toInterfaceToolbarButton(): array
	{
		return [
			'ID' => $this->getId(),
			'HTML' => $this->getCaption(),
			'TITLE' => $this->getDescription(),
			'LINK' => $this->getUrl(),
			'HANDLER' => 'BX.Crm.Router.Instance.closeSettingsMenu',
			'IS_SETTINGS_BUTTON' => true,
			'ICON' => 'btn-import',
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}

	private function getUrl(): string
	{
		$url = Container::getInstance()
			->getRouter()
			->getImportUrl($this->entityTypeId)
		;

		if ($this->categoryId !== null)
		{
			$url->addParams([
				'categoryId' => $this->categoryId,
			]);
		}

		if ($this->origin !== null)
		{
			$url->addParams([
				'origin' => $this->origin->value,
			]);
		}

		$viewEvent = (new ViewEvent())
			->setEntityTypeId($this->entityTypeId)
			->setOrigin($this->origin)
		;

		if ($viewEvent->validate()->isSuccess())
		{
			$url = $viewEvent->buildUri($url);
		}

		return $url;
	}

	private function getCaption(): string
	{
		return (new ImportSettingsItemBuilder($this->entityTypeId))
			->setOrigin($this->origin)
			->setType(ImportSettingsItemBuilder::TYPE_CAPTION)
			->getMessage()
		;
	}

	private function getDescription(): string
	{
		return (new ImportSettingsItemBuilder($this->entityTypeId))
			->setOrigin($this->origin)
			->setType(ImportSettingsItemBuilder::TYPE_DESCRIPTION)
			->getMessage()
		;
	}
}
