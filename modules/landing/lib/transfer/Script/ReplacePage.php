<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script;

use Bitrix\Landing\Transfer\Script\Action\ActionConfig;
use Bitrix\Landing\Transfer\Script\Action\ActivateRights;
use Bitrix\Landing\Transfer\Script\Action\Closet\AppearanceMode;
use Bitrix\Landing\Transfer\Script\Action\DeactivateRights;
use Bitrix\Landing\Transfer\Script\Action\PreparePageAdditionalFields;
use Bitrix\Landing\Transfer\Script\Action\PreparePageAdditionalFieldsBySite;
use Bitrix\Landing\Transfer\Script\Action\CheckDataExists;
use Bitrix\Landing\Transfer\Script\Action\CheckIsNeedImportPage;
use Bitrix\Landing\Transfer\Script\Action\CheckReplacedPage;
use Bitrix\Landing\Transfer\Script\Action\CheckSiteExists;
use Bitrix\Landing\Transfer\Script\Action\PrepareBlocksData;
use Bitrix\Landing\Transfer\Script\Action\PreparePageData;
use Bitrix\Landing\Transfer\Script\Action\PrepareSiteData;
use Bitrix\Landing\Transfer\Script\Action\ReplaceBlocks;
use Bitrix\Landing\Transfer\Script\Action\SaveIndexPage;
use Bitrix\Landing\Transfer\Script\Action\SavePagesList;
use Bitrix\Landing\Transfer\Script\Action\SaveReplacedPageAdditionalFields;
use Bitrix\Landing\Transfer\Script\Action\SaveSiteTemplateLinking;
use Bitrix\Landing\Transfer\Script\Action\SaveSpecialPages;
use Bitrix\Landing\Transfer\Script\Action\SaveTemplates;
use Bitrix\Landing\Transfer\Script\Action\SendAnalytic;
use Bitrix\Landing\Transfer\Script\Action\SendStartEvent;
use Bitrix\Landing\Transfer\Script\Action\SetCheckUniqueAddress;
use Bitrix\Landing\Transfer\Script\Action\SetContextUser;
use Bitrix\Landing\Transfer\Script\Action\FinishEpisode;
use Bitrix\Landing\Transfer\Script\Action\SetScope;
use Bitrix\Landing\Transfer\Script\Action\UnpackAdditionalFiles;
use Bitrix\Landing\Transfer\Script\Action\UpdateBlockLinks;
use Bitrix\Landing\Transfer\Script\Action\UpdateBlockPending;
use Bitrix\Landing\Transfer\Script\Action\UpdateReplacedPageAdditionalFields;
use Bitrix\Landing\Transfer\Script\Action\UpdateReplacedPageAdditionalFiles;

/**
 * Default import - create site, add pages
 */
class ReplacePage implements IScript
{
	public function getMap(): array
	{
		return [
			// Always
			new ActionConfig(SetScope::class),
			(new ActionConfig(CheckDataExists::class))
				->setAppearanceMode(AppearanceMode::NonFinish),
			new ActionConfig(CheckReplacedPage::class),
			new ActionConfig(SetContextUser::class),
			(new ActionConfig(PrepareSiteData::class))
				->setAppearanceMode(AppearanceMode::Intro),
			(new ActionConfig(PreparePageData::class))
				->setAppearanceMode(AppearanceMode::Core),
			(new ActionConfig(PreparePageAdditionalFields::class))
				->setAppearanceMode(AppearanceMode::Core),

			// Just Intro
			(new ActionConfig(SendStartEvent::class))
				->setAppearanceMode(AppearanceMode::Intro),
			(new ActionConfig(SaveSpecialPages::class))
				->setAppearanceMode(AppearanceMode::Intro),
			(new ActionConfig(SavePagesList::class))
				->setAppearanceMode(AppearanceMode::Intro),
			(new ActionConfig(SaveTemplates::class))
				->setAppearanceMode(AppearanceMode::Intro),
			(new ActionConfig(SaveSiteTemplateLinking::class))
				->setAppearanceMode(AppearanceMode::Intro),
			(new ActionConfig(FinishEpisode::class))
				->setAppearanceMode(AppearanceMode::Intro),

			// Core
			(new ActionConfig(CheckSiteExists::class))
				->setAppearanceMode(AppearanceMode::Core),
			(new ActionConfig(CheckIsNeedImportPage::class))
				->setAppearanceMode(AppearanceMode::Core),
			(new ActionConfig(SetCheckUniqueAddress::class))
				->setAppearanceMode(AppearanceMode::Core),
			(new ActionConfig(SaveReplacedPageAdditionalFields::class))
				->setAppearanceMode(AppearanceMode::Core),
			(new ActionConfig(PreparePageAdditionalFieldsBySite::class))
				->setAppearanceMode(AppearanceMode::Core),
			(new ActionConfig(UnpackAdditionalFiles::class))
				->setAppearanceMode(AppearanceMode::Core),
			(new ActionConfig(UpdateReplacedPageAdditionalFields::class))
				->setAppearanceMode(AppearanceMode::Core),
			(new ActionConfig(UpdateReplacedPageAdditionalFiles::class))
				->setAppearanceMode(AppearanceMode::Core),
			(new ActionConfig(PrepareBlocksData::class))
				->setAppearanceMode(AppearanceMode::Core),
			(new ActionConfig(ReplaceBlocks::class))
				->setAppearanceMode(AppearanceMode::Core),
			// todo: index page work in replace?
			(new ActionConfig(SaveIndexPage::class))
				->setAppearanceMode(AppearanceMode::Core),
			(new ActionConfig(FinishEpisode::class))
				->setAppearanceMode(AppearanceMode::Core),

			// Finish
			(new ActionConfig(DeactivateRights::class))
				->setAppearanceMode(AppearanceMode::Finish),
			(new ActionConfig(UpdateBlockPending::class))
				->setAppearanceMode(AppearanceMode::Finish),
			(new ActionConfig(UpdateBlockLinks::class))
				->setAppearanceMode(AppearanceMode::Finish),
			(new ActionConfig(ActivateRights::class))
				->setAppearanceMode(AppearanceMode::Finish),
			(new ActionConfig(SendAnalytic::class))
				->setAppearanceMode(AppearanceMode::Finish),
		];
	}
}
