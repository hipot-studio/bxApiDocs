<?php

namespace Bitrix\Crm\Service\Router\Page\Item;

use Bitrix\Crm\Entity\MessageBuilder\ImportPageTitleBuilder;
use Bitrix\Crm\Import\Enum\Contact\Origin;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Router\AbstractPage;
use Bitrix\Crm\Service\Router\Component\Component;
use Bitrix\Crm\Service\Router\Component\SidePanelWrapper;
use Bitrix\Crm\Service\Router\Contract;
use Bitrix\Crm\Service\Router\Dto\SidePanelAnchorOptions;
use Bitrix\Crm\Service\Router\Dto\SidePanelAnchorRule;
use Bitrix\Crm\Service\Router\Enum\Scope;
use Bitrix\Crm\Service\Router\PageValidator\EntityTypeAvailabilityValidator;
use Bitrix\Crm\Service\Router\Route;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\HttpRequest;
use CCrmOwnerType;

final class ImportPage extends AbstractPage
{
	private readonly int $entityTypeId;

	public function __construct(
		HttpRequest $request,
		?Scope $currentScope,
		int $entityTypeId,
	)
	{
		parent::__construct($request, $currentScope);

		if (!CCrmOwnerType::IsDefined($entityTypeId))
		{
			throw new ArgumentException('entityTypeId not defined');
		}

		if ($this->currentScope === Scope::AutomatedSolution && !CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId))
		{
			throw new ArgumentException('entityTypeId not correct');
		}

		$this->entityTypeId = $entityTypeId;
	}

	public function component(): Contract\Component
	{
		return new Component('bitrix:crm.item.import', [
			'entityTypeId' => $this->entityTypeId,
		]);
	}

	public static function routes(): array
	{
		return [
			new Route('type/{entityTypeId}/import/'),
		];
	}

	public static function scopes(): array
	{
		return [
			Scope::Crm,
			Scope::AutomatedSolution,
		];
	}

	public static function getSidePanelAnchorRules(): array
	{
		return [
			(new SidePanelAnchorRule("type/[A-Za-z0-9-_]+/import/?"))
				->scopes(self::scopes())
				->configureOptions(function (SidePanelAnchorOptions $options) {
					$options
						->setCacheable(false)
						->setAllowChangeHistory(false)
						->setWidth(900)
					;
				})
			,
		];
	}

	protected function configureSidePanel(SidePanelWrapper $sidePanel): void
	{
		$sidePanel->pageMode = false;

		$categoryId = $this->request->get('categoryId');
		$sidePanel->pageModeBackUrl = Container::getInstance()
			->getRouter()
			->getItemListUrl(
				$this->entityTypeId,
				$categoryId,
			);

		$sidePanel->isUsePadding = false;
		$sidePanel->isUseBackgroundContent = false;
		$sidePanel->isPlainView = true;
	}

	public function title(): ?string
	{
		$origin = $this->request->get('origin');

		return (new ImportPageTitleBuilder($this->entityTypeId))
			->setOrigin(Origin::tryFrom($origin) ?? Origin::Custom)
			->getMessage()
		;
	}

	public function canUseFavoriteStar(): ?bool
	{
		return false;
	}

	protected function getPageValidators(): array
	{
		return [
			...parent::getPageValidators(),
			new EntityTypeAvailabilityValidator($this->entityTypeId),
		];
	}
}
