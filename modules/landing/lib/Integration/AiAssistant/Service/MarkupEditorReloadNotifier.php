<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Service;

use Bitrix\Landing\Copilot\Generation\Scenario\ChangeMarkupSite;
use Bitrix\Main\Loader;
use Bitrix\Pull;

/**
 * Reloads the open editor of a markup page after a chat tool changed what the page shows without a new
 * package: a CRM form rebound to its marker, the fields of a form of the page. A markup block is never
 * reloaded on its own - it loses its frame - so the editor reloads the whole page, on the command it already
 * reloads on after a package edit. With no generation the command reaches every open editor of the page of
 * the user.
 */
class MarkupEditorReloadNotifier
{
	public const REASON_CRM_FORM_SWITCH = 'crm_form_switch';
	public const REASON_CRM_FORM_FIELDS_UPDATE = 'crm_form_fields_update';

	public function notify(int $userId, int $landingId, int $blockId, string $reason): void
	{
		if ($userId <= 0 || $landingId <= 0 || !Loader::includeModule('pull'))
		{
			return;
		}

		try
		{
			Pull\Event::add(
				$userId,
				[
					'module_id' => 'landing',
					'command' => 'LandingCopilotGeneration:' . ChangeMarkupSite::EVENT_FINISH,
					'params' => [
						'landingId' => $landingId,
						'params' => [
							'reason' => $reason,
							'blockId' => $blockId,
						],
					],
				],
			);
			Pull\Event::send();
		}
		catch (\Throwable)
		{
			// the change is saved already: a lost notice of the editor must not turn it into an error
		}
	}
}
