<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Router\Page\MessageSender;

use Bitrix\Crm\Service\Router\AbstractPage;
use Bitrix\Crm\Service\Router\Component\Component;
use Bitrix\Crm\Service\Router\Component\SidePanelWrapper;
use Bitrix\Crm\Service\Router\Contract;
use Bitrix\Crm\Service\Router\Dto\SidePanelAnchorOptions;
use Bitrix\Crm\Service\Router\Dto\SidePanelAnchorRule;
use Bitrix\Crm\Service\Router\Enum\Scope;
use Bitrix\Crm\Service\Router\Route;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;

final class CustomTemplatesListPage extends AbstractPage
{
	public function __construct(
		HttpRequest $request,
		?Scope $currentScope,
		private readonly string $scene,
		private readonly string $targetId,
	)
	{
		parent::__construct($request, $currentScope);
		$this->title = Loc::getMessage('CRM_MS_CUSTOM_TEMPLATES_PAGE_TITLE');
		$this->canUseFavoriteStar = false;
	}

	public function component(): Contract\Component
	{
		return new Component(
			'bitrix:crm.messagesender.custom_template.list',
			parameters: [
				'SCENE' => $this->scene,
				'TARGET_ID' => $this->targetId,
			],
		);
	}

	/**
	 * @inheritDoc
	 */
	public static function routes(): array
	{
		return [
			new Route('messagesender/templates/{scene}/{targetId}/'),
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
			(new SidePanelAnchorRule('messagesender/templates/[^/]+/[^/]+/?'))
				->scopes(self::scopes())
				->configureOptions(static function (SidePanelAnchorOptions $options): void {
					$options
						->setAllowChangeHistory(false)
						->setCustomLeftBoundary(40)
					;
				})
			,
		];
	}

	protected function configureSidePanel(SidePanelWrapper $sidePanel): void
	{
		$sidePanel->isUseBitrix24Theme = true;
	}
}
