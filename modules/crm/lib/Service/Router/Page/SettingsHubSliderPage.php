<?php

namespace Bitrix\Crm\Service\Router\Page;

use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\SettingsHub;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Router\AbstractPage;
use Bitrix\Crm\Service\Router\Component\SidePanelWrapper;
use Bitrix\Crm\Service\Router\Contract;
use Bitrix\Crm\Service\Router\Enum\Scope;
use Bitrix\Crm\Service\Router\PageValidator\PermissionValidator;
use Bitrix\Crm\Service\Router\Route;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubNavigationRegistry;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubWrappedNavigationItem;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubWrappedNavigationItemLifecycle;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Rendering\SettingsHubRenderContext;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\SettingsHubFactory;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\InvalidOperationException;
use Bitrix\Main\Routing\RoutingConfiguration;

final class SettingsHubSliderPage extends AbstractPage
{
	protected ?bool $canUseFavoriteStar = false;

	private readonly ?SettingsHubWrappedNavigationItem $item;
	private readonly UserPermissions $userPermissions;
	private readonly SettingsHubRenderContext $renderContext;

	public function __construct(
		string $navigationId,
		HttpRequest $request,
		?Scope $currentScope,
		SettingsHubNavigationRegistry $registry,
		SettingsHubFactory $settingsHubFactory,
	)
	{
		parent::__construct($request, $currentScope);
		$this->userPermissions = Container::getInstance()->getUserPermissions();
		$this->renderContext = $settingsHubFactory->createRenderContext();

		$item = $registry->get($navigationId);

		$this->item = $item instanceof SettingsHubWrappedNavigationItem ? $item : null;
	}

	public function component(): Contract\Component
	{
		if ($this->item === null)
		{
			throw new InvalidOperationException('Unknown wrapped navigation item.');
		}

		return $this->item->component($this->request);
	}

	public static function routes(): array
	{
		return [
			(new Route('settings-hub/slider/{navigationId}/'))
				->configure(static function (RoutingConfiguration $configuration): void {
					$configuration
						->methods(['GET', 'POST'])
						->where('navigationId', '[a-z0-9-]+')
					;
				})
			,
		];
	}

	public static function scopes(): array
	{
		return [
			Scope::Crm,
		];
	}

	public static function isActive(): bool
	{
		return Feature::enabled(SettingsHub::class);
	}

	protected function getPageValidators(): array
	{
		return [
			...parent::getPageValidators(),
			new PermissionValidator(
				fn (): bool => $this->item !== null
					&& $this->item->isVisible($this->userPermissions)
					&& $this->item->canRead($this->userPermissions),
			),
		];
	}

	protected function beforeRender(): void
	{
		if ($this->item instanceof SettingsHubWrappedNavigationItemLifecycle)
		{
			$this->item->beforeRender($this->renderContext);
		}
	}

	protected function configureSidePanel(SidePanelWrapper $sidePanel): void
	{
		if ($this->item instanceof SettingsHubWrappedNavigationItemLifecycle)
		{
			$this->item->configureSidePanel($sidePanel);
		}
	}
}
