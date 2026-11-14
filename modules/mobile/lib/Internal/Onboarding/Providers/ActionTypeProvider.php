<?php

namespace Bitrix\Mobile\Internal\Onboarding\Providers;

use Bitrix\Mobile\Context;
use Bitrix\Mobile\Internal\Onboarding\PushType;
use Bitrix\Mobile\Tab\Manager;

/**
 * Picks the action type carried by the push. A type whose target lives in a tab
 * degrades to the More-menu action when that tab is not active for the user; a type
 * without a More-menu variant is sent as is and never asks the tab manager.
 */
class ActionTypeProvider
{
	private ?Manager $tabManager = null;

	public function __construct(private readonly int $userId)
	{}

	public function getActionType(PushType $type): string
	{
		$actionMoreType = $type->getActionMoreType();
		if ($actionMoreType === null)
		{
			return $type->getActionType();
		}

		return $this->isTabActive($type) ? $type->getActionType() : $actionMoreType;
	}

	/**
	 * Action type for a push of the activation series (ACTION-MAP). A step whose campaign
	 * target differs from the ordinary one declares that target on PushType itself - the
	 * create forms of the task and deal steps, the QR-auth desktop handler of the web step -
	 * and such an action opens a screen over whatever is showing, so it depends on no tab
	 * and is never asked to degrade. Everything else keeps the tab action of getActionType()
	 * together with its More-menu degradation.
	 */
	public function getCampaignActionType(PushType $type): string
	{
		return $type->getCampaignActionType() ?? $this->getActionType($type);
	}

	private function isTabActive(PushType $type): bool
	{
		$manager = $this->getTabManager();
		if ($manager === null)
		{
			return false;
		}

		try
		{
			return $manager->isTabActive($type->value);
		}
		catch (\Throwable $e)
		{
			AddMessage2Log('Onboarding push: isTabActive failed: ' . $e->getMessage(), 'mobile');

			return false;
		}
	}

	private function getTabManager(): ?Manager
	{
		if ($this->tabManager !== null)
		{
			return $this->tabManager;
		}

		if (!class_exists(Context::class) || !class_exists(Manager::class))
		{
			return null;
		}

		try
		{
			$context = new Context(['userId' => $this->userId]);
			$this->tabManager = new Manager($context);
		}
		catch (\Throwable $e)
		{
			AddMessage2Log('Onboarding push: tab manager init failed - ' . $e->getMessage(), 'mobile');

			return null;
		}

		return $this->tabManager;
	}
}
