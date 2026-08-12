<?php

namespace Bitrix\Intranet\UI\LeftMenu\Preset;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

class Sync extends PresetAbstract
{
	const CODE = 'sync';

	public static function isAvailable(): bool
	{
		return Loader::includeModule('call') && \Bitrix\Call\Settings::isSyncPresetEnabled();
	}

	public function getName(): string
	{
		return Loc::getMessage('MENU_PRESET_SYNC_TITLE');
	}

	public function getStructure(): array
	{
		return [
			'shown' => [
				'menu_teamwork' => [
					'menu_sync',
					'menu_im_messenger',
					'menu_calendar',
				],
				'menu_booking',
			],
			'hidden' => [
				'menu_live_feed',
				'menu_im_collab',
				'menu_documents',
				'menu_boards',
				'menu_files',
				'menu_external_mail',
				'menu_all_groups',
				'menu_all_spaces',
				'menu_tasks',
				'menu_crm_favorite',
				'menu_crm_store',
				'menu_marketing',
				'menu_sites',
				'menu_shop',
				'menu_sign_b2e',
				'menu_sign',
				'menu_bi_constructor',
				'menu_company',
				'menu_bizproc_sect',
				'menu_ai_agents',
				'menu_automation',
				'menu_marketplace_sect',
				'menu_devops_sect',
				'menu_mcp_integrations',
				'menu_timeman_sect',
				'menu_rpa',
				'menu_contact_center',
				'menu_crm_tracking',
				'menu_analytics',
				'menu-sale-center',
				'menu_openlines',
				'menu_telephony',
				'menu_ai',
				'menu_onec_sect',
				'menu_tariff',
				'menu_updates',
				'menu_knowledge',
				'menu_conference',
				'menu_configs_sect',
			],
		];
	}
}
