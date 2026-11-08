<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Sender\Avatar;

use Bitrix\Mail\Internal\Service\Avatar\AvatarImageProcessor;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Localization\Loc;

final class SenderAvatarViewModelBuilder
{
	private const UPLOADER_CONTROLLER = 'mail.fileUploader.senderAvatarUploaderController';

	private const LABEL_KEYS = [
		'open' => 'MAIL_SENDER_AVATAR_LABEL_OPEN',
		'title' => 'MAIL_SENDER_AVATAR_LABEL_TITLE',
		'select' => 'MAIL_SENDER_AVATAR_LABEL_SELECT',
		'replace' => 'MAIL_SENDER_AVATAR_LABEL_REPLACE',
		'delete' => 'MAIL_SENDER_AVATAR_LABEL_DELETE',
		'confirm' => 'MAIL_SENDER_AVATAR_LABEL_CONFIRM',
		'cancel' => 'MAIL_SENDER_AVATAR_LABEL_CANCEL',
		'retry' => 'MAIL_SENDER_AVATAR_LABEL_RETRY',
		'loading' => 'MAIL_SENDER_AVATAR_LABEL_LOADING',
		'saving' => 'MAIL_SENDER_AVATAR_LABEL_SAVING',
		'deletePending' => 'MAIL_SENDER_AVATAR_LABEL_DELETE_PENDING',
		'replacePending' => 'MAIL_SENDER_AVATAR_LABEL_REPLACE_PENDING',
		'uploaderError' => 'MAIL_SENDER_AVATAR_LABEL_UPLOADER_ERROR',
	];

	private const ERROR_CODES = [
		ExternalSenderResolver::ERROR_ACCESS_DENIED,
		ExternalSenderResolver::ERROR_INVALID_SENDER,
		SenderAvatarResourceService::ERROR_ACCESS_DENIED,
		SenderAvatarResourceService::ERROR_NOT_SUPPORTED,
		SenderAvatarChange::ERROR_INVALID_CHANGE,
		SenderAvatarService::ERROR_VERSION_CONFLICT,
		AvatarImageProcessor::ERROR_UPLOAD_INVALID,
		AvatarImageProcessor::ERROR_FORMAT_INVALID,
		AvatarImageProcessor::ERROR_IMAGE_INVALID,
		AvatarImageProcessor::ERROR_LIMIT_EXCEEDED,
		AvatarImageProcessor::ERROR_SAVE_FAILED,
	];

	public static function build(
		int $messageId,
		string $messageAccessToken,
		array $state,
		?string $avatarUrl = null,
	): array
	{
		self::loadMessages();
		$revision = (int)($state['revision'] ?? 0);
		$hasAvatar = (bool)($state['hasAvatar'] ?? false);
		$canManage = (bool)($state['canManage'] ?? false);
		$controllerOptions = ['messageId' => $messageId];
		if ($messageAccessToken !== '')
		{
			$controllerOptions['messageAccessToken'] = $messageAccessToken;
		}

		if ($hasAvatar && $avatarUrl === null)
		{
			$urlParameters = [
				'messageId' => $messageId,
				'revision' => $revision,
			];
			if ($messageAccessToken !== '')
			{
				$urlParameters['messageAccessToken'] = $messageAccessToken;
			}
			$avatarUrl = UrlManager::getInstance()
				->create('mail.api.senderavatar.view', $urlParameters)
				->getUri()
			;
		}

		return [
			'messageId' => $messageId,
			'revision' => $revision,
			'hasAvatar' => $hasAvatar,
			'url' => $hasAvatar ? $avatarUrl : null,
			'canManage' => $canManage,
			'uploader' => $canManage ? [
				'controller' => self::UPLOADER_CONTROLLER,
				'controllerOptions' => $controllerOptions,
			] : null,
			'labels' => self::localizedMap(self::LABEL_KEYS),
			'errorMessages' => self::localizedErrors(),
		];
	}

	private static function loadMessages(): void
	{
		Loc::loadMessages(dirname(__DIR__, 4) . '/controller/senderavatar.php');
	}

	private static function localizedMap(array $keys): array
	{
		$result = [];
		foreach ($keys as $name => $key)
		{
			$result[$name] = (string)(Loc::getMessage($key) ?? $key);
		}

		return $result;
	}

	private static function localizedErrors(): array
	{
		$result = [];
		foreach (self::ERROR_CODES as $code)
		{
			$result[$code] = (string)(Loc::getMessage($code) ?? $code);
		}

		return $result;
	}
}
