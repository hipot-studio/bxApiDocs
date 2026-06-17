<?php

namespace Bitrix\Crm\Tour\ClientFields;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Tour\Base;
use Bitrix\Crm\Tour\Mixin\HasEntitySupport;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;

abstract class AbstractClientFieldsEntityList extends Base
{
	use HasEntitySupport;

	protected string $targetId = '';

	public static function getInstanceByEntityTypeId(int $entityTypeId): ?self
	{
		if ($entityTypeId === \CCrmOwnerType::SmartInvoice)
		{
			$clientFieldsTour = ClientFieldsSmartInvoiceList::getInstance();
		}
		elseif ($entityTypeId === \CCrmOwnerType::SmartDocument)
		{
			$clientFieldsTour = ClientFieldsSmartDocumentList::getInstance();
		}
		elseif (\CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId))
		{
			$clientFieldsTour = ClientFieldsDynamicItemList::getInstance();
		}
		else
		{
			return null;
		}

		$clientFieldsTour->setEntityTypeId($entityTypeId);

		return $clientFieldsTour;
	}

	public function setTargetId(string $targetId): self
	{
		$this->targetId = $targetId;

		return $this;
	}

	protected function canShow(): bool
	{
		return
			$this->targetId !== ''
			&& !$this->isUserSeenTour()
			&& $this->canShowByEntityTypeId()
			&& $this->canShowByPermissions()
		;
	}

	abstract protected function canShowByEntityTypeId(): bool;

	protected function canShowByPermissions(): bool
	{
		$entityTypePermissions = Container::getInstance()->getUserPermissions()->entityType();

		return
			$entityTypePermissions->canReadItems(\CCrmOwnerType::Contact)
			|| $entityTypePermissions->canReadItems(\CCrmOwnerType::Company)
		;
	}

	protected function getSteps(): array
	{
		return [
			[
				'id' => static::OPTION_NAME,
				'title' => $this->getTitle(),
				'text' => $this->getText(),
				'position' => 'bottom',
				'target' => $this->targetId,
				'article' => $this->getArticleId(),
			],
		];
	}

	protected function getTitle(): string
	{
		return (string)Loc::getMessage('CRM_TOUR_CLIENT_FIELDS_ITEM_LIST_TITLE');
	}

	protected function getText(): string
	{
		return (string)Loc::getMessage('CRM_TOUR_CLIENT_FIELDS_ITEM_LIST_TEXT');
	}

	protected function getArticleId(): int
	{
		return 24206616;
	}

	protected function getOptions(): array
	{
		return [
			'hideTourOnMissClick' => true,
			'steps' => [
				'popup' => [
					'width' => 400,
				],
			],
		];
	}

	protected function getPortalMaxCreatedDate(): ?DateTime
	{
		return new DateTime('01.04.2026', 'd.m.Y');
	}

	protected function getShowDeadline(): ?DateTime
	{
		return new DateTime('01.06.2026', 'd.m.Y');
	}
}
