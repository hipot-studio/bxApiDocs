<?php

namespace Bitrix\Crm\Integration\UI\UserField;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\CopilotFeatureCode;
use Bitrix\Crm\Integration\AI\EventHandler;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

final class RichTextCopilotProvider
{
	public static function onGetCopilotOptions(Event $event): EventResult
	{
		$userField = $event->getParameter('userField');
		if (!is_array($userField))
		{
			return new EventResult(EventResult::UNDEFINED, null, 'crm');
		}

		$entityId = (string)($userField['ENTITY_ID'] ?? '');
		if (!str_starts_with($entityId, 'CRM_'))
		{
			return new EventResult(EventResult::UNDEFINED, null, 'crm');
		}

		static $isFillTextEnabled = null;
		$isFillTextEnabled ??= AIManager::isEnabledInGlobalSettings(EventHandler::SETTINGS_FILL_CRM_TEXT_ENABLED_CODE);

		if (!$isFillTextEnabled)
		{
			return new EventResult(EventResult::UNDEFINED, null, 'crm');
		}

		$fieldName = (string)($userField['FIELD_NAME'] ?? '');

		return new EventResult(
			EventResult::SUCCESS,
			[
				'copilot' => [
					'copilotOptions' => [
						'moduleId' => 'crm',
						'contextId' => 'uf-' . $entityId . '-' . $fieldName,
						'feature' => CopilotFeatureCode::USER_FIELD_RICH_TEXT,
						'category' => 'crm_comment_field',
						'autoHide' => true,
					],
					'triggerBySpace' => true,
				],
			],
			'crm',
		);
	}
}
