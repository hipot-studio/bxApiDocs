<?php

namespace Bitrix\Crm\Service\Router\Page\MessageSender;

use Bitrix\Crm\Service\Router\AbstractPage;
use Bitrix\Crm\Service\Router\Component\Component;
use Bitrix\Crm\Service\Router\Component\SidePanelWrapper;
use Bitrix\Crm\Service\Router\Contract;
use Bitrix\Crm\Service\Router\Dto\SidePanelAnchorOptions;
use Bitrix\Crm\Service\Router\Dto\SidePanelAnchorRule;
use Bitrix\Crm\Service\Router\Enum\Scope;
use Bitrix\Crm\Service\Router\Route;

final class ConnectionsPage extends AbstractPage
{
	public function component(): Contract\Component
	{
		return new Component(
			'bitrix:crm.messagesender.connections',
			parameters: [
				'analytics' => $this->request->get('analytics'),
			],
		);
	}

	/**
	 * @inheritDoc
	 */
	public static function routes(): array
	{
		return [
			new Route('messagesender/connections'),
		];
	}

	/**
	 * @inheritDoc
	 */
	public static function scopes(): array
	{
		return [
			Scope::Crm,
		];
	}

	public static function getSidePanelAnchorRules(): array
	{
		return [
			(new SidePanelAnchorRule("messagesender/connections"))
				->scopes(self::scopes())
				->configureOptions(function (SidePanelAnchorOptions $options) {
					$options
						->setWidth(920)
					;
				})
			,
		];
	}

	protected function configureSidePanel(SidePanelWrapper $sidePanel): void
	{
		$sidePanel->pageMode = false;
		$sidePanel->isUseBackgroundContent = false;
		$sidePanel->isUsePadding = false;

		$roots = $this->currentScope->roots();
		$sidePanel->pageModeBackUrl = reset($roots);
	}
}
