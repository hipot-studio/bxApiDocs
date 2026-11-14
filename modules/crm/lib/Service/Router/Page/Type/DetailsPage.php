<?php

namespace Bitrix\Crm\Service\Router\Page\Type;

use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\SmartProcessCrmHub;
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
use Bitrix\Crm\Service\UserPermissions\ConfigurationCallSite;
use Bitrix\Crm\Service\UserPermissions\ConfigurationScope;
use Bitrix\Main\HttpRequest;

final class DetailsPage extends AbstractPage
{
	private const COMPONENT_NAME = 'bitrix:crm.type.detail';

	public function __construct(
		private readonly int $entityTypeId,
		HttpRequest $request,
		?Scope $currentScope,
	)
	{
		parent::__construct($request, $currentScope);
	}

	public function component(): Contract\Component
	{
		return new Component(
			name: self::COMPONENT_NAME,
			parameters: [
				'entityTypeId' => $this->entityTypeId,
			],
		);
	}

	public static function routes(): array
	{
		return [
			(new Route('type/detail/{entityTypeId}/'))
				->setRelatedComponent(self::COMPONENT_NAME)
			,
		];
	}

	public static function scopes(): array
	{
		return [
			Scope::Crm,
			Scope::AutomatedSolution,
			Scope::Automation,
		];
	}

	protected function configureSidePanel(SidePanelWrapper $sidePanel): void
	{
		$sidePanel->isUsePadding = false;

		// The legacy form keeps its tabs in the menu of the slider frame: a direct address opens it in a slider.
		// The hub works as a standalone page.
		if (!Feature::enabled(SmartProcessCrmHub::class))
		{
			$sidePanel->pageMode = false;
			$sidePanel->pageModeBackUrl = $this->getBackUrl();
		}
	}

	/**
	 * The list of the types of the section asks a right of its own, wider than the one of the card of a type
	 * of an automated solution, so whoever may not open the list goes back to the items of the type instead,
	 * if they may read them.
	 * The address is never null: the loader of the slider does not leave the page without one.
	 */
	private function getBackUrl(): string
	{
		return $this->getTypeListUrl() ?? $this->getItemListUrl() ?? SITE_DIR;
	}

	private function getTypeListUrl(): ?string
	{
		if (!$this->canReadTypeList())
		{
			return null;
		}

		$router = Container::getInstance()->getRouter();

		return match ($this->currentScope) {
			Scope::Automation, Scope::AutomatedSolution => $router->getExternalTypeListUrl()->getUri(),
			default => $router->getTypeListUrl()?->getUri(),
		};
	}

	private function getItemListUrl(): ?string
	{
		if (
			$this->entityTypeId <= 0
			|| !Container::getInstance()->getUserPermissions()->entityType()->canReadItems($this->entityTypeId)
		)
		{
			return null;
		}

		return Container::getInstance()->getRouter()->getItemListUrl($this->entityTypeId)?->getUri();
	}

	/**
	 * The rights crm.type.list asks with the default filter of its grid.
	 */
	private function canReadTypeList(): bool
	{
		$userPermissions = Container::getInstance()->getUserPermissions();

		return match ($this->currentScope) {
			Scope::Automation, Scope::AutomatedSolution => $userPermissions->automatedSolution()->canEdit(),
			default => $userPermissions->configuration()->canManageDynamicTypes(
				ConfigurationCallSite::DynamicTypePermissions,
				ConfigurationScope::ofDynamicTypeTarget(null),
			),
		};
	}

	public static function getSidePanelAnchorRules(): array
	{
		return [
			(new SidePanelAnchorRule("type/detail/(\d+)"))
				->scopes(self::scopes())
				->configureOptions(function (SidePanelAnchorOptions $options) {
					$options
						->setWidth(876)
						->setCacheable(false)
						->setAllowChangeHistory(false)
					;
				})
			,
		];
	}

	protected function getPageValidators(): array
	{
		$validators = parent::getPageValidators();
		if ($this->entityTypeId > 0)
		{
			$validators[] = new EntityTypeAvailabilityValidator($this->entityTypeId);
		}

		return $validators;
	}
}
