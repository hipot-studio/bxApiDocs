<?php

namespace Bitrix\Crm\Timeline\Comment;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\EventHandler;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Timeline\CommentController;
use Bitrix\Crm\Timeline\Entity\TimelineTable;
use Bitrix\Crm\Timeline\TimelineType;
use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Uf\Integration\DiskUploaderController;
use Bitrix\Main\Loader;
use Bitrix\Main\Text\Emoji;

class EditorConfigProvider
{
	public function getConfig(int $commentId): array
	{
		$comment = $commentId > 0
			? TimelineTable::getRow([
				'select' => ['COMMENT'],
				'filter' => ['=ID' => $commentId, '=TYPE_ID' => TimelineType::COMMENT],
			])
			: null;
		$config = [
			'text' => Emoji::decode((string)($comment['COMMENT'] ?? '')),
			'attachedIds' => [],
			'files' => [],
			'missingAttachedIds' => [],
			'missingInlineReferences' => [],
			'sizedStubInlineReferences' => [],
			'mention' => ['dialogOptions' => ['entities' => [
				['id' => 'user'],
				['id' => 'department', 'options' => ['selectMode' => 'usersOnly']],
				['id' => 'project'],
			]]],
		];

		if ($commentId > 0 && Loader::includeModule('disk'))
		{
			$config = $this->addFiles($config, $commentId);
		}

		if ($this->isCopilotAvailable())
		{
			$config['copilot'] = ['copilotOptions' => [
				'moduleId' => 'crm',
				'category' => 'crm_timeline_comment',
				'contextId' => 'crm_timeline_comment_editor_' . $commentId,
				'autoHide' => true,
			]];
		}

		return $config;
	}

	private function addFiles(array $config, int $commentId): array
	{
		global $USER_FIELD_MANAGER;

		$fields = $USER_FIELD_MANAGER->GetUserFields(CommentController::UF_FIELD_NAME, $commentId);
		$manager = Driver::getInstance()->getUserFieldManager();
		$attachedObjects = $manager->getAttachedObjectByEntity(
			CommentController::UF_FIELD_NAME,
			$commentId,
			CommentController::UF_COMMENT_FILE_NAME,
		);
		foreach ((array)($fields[CommentController::UF_COMMENT_FILE_NAME]['VALUE'] ?? []) as $value)
		{
			$attachedId = (int)$value;
			// Disk's eager OBJECT join omits attachments whose source file has been deleted.
			$attached = $attachedObjects[$attachedId] ?? AttachedObject::loadById($attachedId);
			if (
				$attached !== null
				&& $manager->belongsToEntity($attached, CommentController::UF_FIELD_NAME, $commentId)
			)
			{
				$config['attachedIds'][] = $attachedId;
			}
		}

		$infoById = [];
		foreach ($this->loadFileInfo($config['attachedIds']) as $info)
		{
			$infoById[(int)$info['serverFileId']] = $info;
		}

		$missingReferences = [];
		$stubReferences = [];
		$userId = Container::getInstance()->getContext()->getUserId();
		foreach ($config['attachedIds'] as $attachedId)
		{
			if (isset($infoById[$attachedId]))
			{
				$config['files'][] = $infoById[$attachedId];

				continue;
			}

			$attached = AttachedObject::loadById($attachedId);
			$file = $attached?->getFile();
			if ($file !== null && $this->canReadAttachedObject($attached, $userId))
			{
				$config['files'][] = [
					'serverFileId' => $attachedId,
					'serverId' => $attachedId,
					'name' => $file->getName(),
					'size' => $file->getSize(),
					'type' => 'application/octet-stream',
					'isImage' => false,
					'isVideo' => false,
					'downloadUrl' => '',
					'serverPreviewUrl' => '',
					'customData' => ['objectId' => (int)$attached->getObjectId()],
				];
				$stubReferences[] = (string)$attachedId;
				$stubReferences[] = 'n' . $attached->getObjectId();
			}
			else
			{
				$config['missingAttachedIds'][] = $attachedId;
				$missingReferences[] = (string)$attachedId;
				if ($attached !== null)
				{
					$missingReferences[] = 'n' . $attached->getObjectId();
				}
			}
		}

		preg_match_all('/\[DISK FILE ID\s*=\s*(n?\d+)/i', $config['text'], $matches);
		$config['missingInlineReferences'] = array_values(array_unique(array_filter(
			$matches[1],
			static fn(string $reference): bool => in_array($reference, $missingReferences, true),
		)));

		preg_match_all('/\[DISK FILE ID\s*=\s*(n?\d+)([^\]]*)\]/i', $config['text'], $matches, PREG_SET_ORDER);
		foreach ($matches as $match)
		{
			if (
				in_array($match[1], $stubReferences, true)
				&& preg_match('/\s(?:width|height)\s*=\s*\d+/i', $match[2]) === 1
			)
			{
				$config['sizedStubInlineReferences'][] = $match[1];
			}
		}
		$config['sizedStubInlineReferences'] = array_values(array_unique($config['sizedStubInlineReferences']));

		return $config;
	}

	protected function loadFileInfo(array $attachedIds): array
	{
		return DiskUploaderController::getFileInfo($attachedIds);
	}

	protected function canReadAttachedObject(AttachedObject $attached, int $userId): bool
	{
		return $attached->canRead($userId);
	}

	protected function isCopilotAvailable(): bool
	{
		return AIManager::isEnabledInGlobalSettings(EventHandler::SETTINGS_FILL_CRM_TEXT_ENABLED_CODE);
	}
}
