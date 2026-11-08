<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\WebForm;

use Bitrix\Crm\SiteButton;
use Bitrix\Crm\UI\Webpack;
use Bitrix\Crm\WebForm\Form;
use Bitrix\Crm\WebForm\Manager;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Type\Date;

/**
 * Rebuilds the published packages of one CRM form, in the order and with the tolerance of the
 * legacy save.
 *
 * Every write that changes what the published package carries goes through here. The package is
 * composed out of the settings of the form at the moment it is built, so a setting written without
 * a rebuild is answered by the read of REST while the visitors of the page the form is placed on
 * keep receiving the previous one.
 *
 * Only the package of the form itself answers for the outcome: a failure of it leaves the record
 * pointing at a file that is gone, so it is reported. The shared packages keep the tolerance of
 * legacy: they belong to the portal, not to this form, and their build lock is expected to be busy
 * now and then.
 *
 * @internal
 */
final class FormScriptBuilder
{
	/** The name a refusal of this operation is carried by. */
	public const OPERATION = 'buildScripts';

	public function build(int $formId): bool
	{
		$resourceBooking = Webpack\Form\ResourceBooking::instance();
		if (ModuleManager::isModuleInstalled('calendar') && !$resourceBooking->isBuilt())
		{
			$resourceBooking->build();
		}

		$booking = Webpack\Form\Booking::instance();
		if (ModuleManager::isModuleInstalled('booking') && !$booking->isBuilt())
		{
			$booking->build();
		}

		$app = Webpack\Form\App::instance();
		if (!$app->isBuilt(new Date()) && $app->build())
		{
			Webpack\Form::addCheckResourcesAgent();
		}

		$isBuilt = Webpack\Form::instance($formId)->build();
		if (Manager::isEmbeddingAvailable())
		{
			SiteButton\Manager::updateScriptCacheWithForm($formId);
		}

		// The cache tag is reset before the page of the form is created.
		Form::cleanCacheByTag($formId);

		return $isBuilt;
	}
}
