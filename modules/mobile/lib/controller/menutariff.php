<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Controller;

use Bitrix\Intranet\ActionFilter\IntranetUser;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\Response\AjaxJson;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Mobile\Config\Feature;
use Bitrix\Mobile\Feature\MyCompanyFeature;
use Bitrix\Mobile\Internal\Integration\Market\Subscription;
use Bitrix\Mobile\Menu\Tariff\TariffPlateProvider;

Loc::loadMessages(__FILE__);

class MenuTariff extends Controller
{
	public function configureActions(): array
	{
		$additionalPrefilters = [];

		if (Loader::includeModule('intranet'))
		{
			$additionalPrefilters[] = new IntranetUser();
		}

		return [
			'activateSubscriptionDemo' => [
				'+prefilters' => [
					new CloseSession(),
					...$additionalPrefilters,
				],
			],
		];
	}

	/**
	 * @ajaxAction mobile.MenuTariff.activateSubscriptionDemo
	 */
	public function activateSubscriptionDemoAction(): AjaxJson
	{
		$provider = new TariffPlateProvider();

		// The same gate the plate is rendered by: the action must not outlive the UI it belongs to.
		// The demo of the subscription is offered in the section alone, so the rollout of the section
		// joins that gate; the employee half of the section is already held by the intranet prefilter
		// and by the intranet user term of isVisible().
		if (Feature::isDisabled(MyCompanyFeature::class) || !$provider->isVisible())
		{
			return AjaxJson::createError(
				new ErrorCollection([
					new Error(
						Loc::getMessage('MOBILE_MENU_TARIFF_SUBSCRIPTION_NOT_AVAILABLE'),
						422,
					),
				]),
			);
		}

		$result = (new Subscription())->activateDemo();
		if (!$result->isSuccess())
		{
			return AjaxJson::createError(
				new ErrorCollection([
					new Error($this->getActivationErrorMessage($result), 422),
				]),
			);
		}

		$this->invalidateMenuCache();

		return AjaxJson::createSuccess([
			'subscription' => $provider->getSubscriptionData(),
		]);
	}

	private function getActivationErrorMessage(Result $result): string
	{
		$errors = $result->getErrors();
		$firstError = reset($errors);
		$message = $firstError ? (string)$firstError->getMessage() : '';

		return $message === ''
			? (string)Loc::getMessage('MOBILE_MENU_TARIFF_SUBSCRIPTION_ACTIVATION_ERROR')
			: $message
		;
	}

	private function invalidateMenuCache(): void
	{
		$userId = (int)$this->getCurrentUser()?->getId();
		if ($userId <= 0)
		{
			return;
		}

		// Only the initiator's menu is dropped: the tariff plate is built outside MenuListCache,
		// so clearing the portal-wide tag would rebuild every user's menu for nothing.
		$taggedCache = Application::getInstance()->getTaggedCache();
		$taggedCache->clearByTag('mobile_custom_menu' . $userId);
	}
}
