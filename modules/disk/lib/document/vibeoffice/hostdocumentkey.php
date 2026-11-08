<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\File;
use Bitrix\Main\ArgumentException;

final class HostDocumentKey
{
	public const CONTEXT_KEY = 'host_doc_key';
	public const MODE_LEGACY = 'legacy';
	public const MODE_STABLE = 'stable';

	public static function resolve(DocumentSession $session): string
	{
		$key = DocumentSessionManager::extractVoMetadata($session)[self::CONTEXT_KEY] ?? null;

		return is_string($key) && $key !== '' ? $key : $session->getExternalHash();
	}

	public static function isStable(string $key): bool
	{
		return preg_match('/^vo1_[a-f0-9]{64}$/D', $key) === 1;
	}

	public static function createScope(DocumentSession $session, File $freshFile): array
	{
		$objectId = (int)$freshFile->getRealObjectId();
		$context = $session->getContext();
		$externalLinkId = (int)$session->getExternalLinkId();
		if (
			$session->getService() !== DocumentService::Vibeoffice
			|| $objectId <= 0
			|| $objectId !== (int)$session->getObjectId()
			|| !$context
			|| $context->getObjectId() !== $objectId
			|| (int)$context->getExternalLinkId() !== $externalLinkId
			|| !in_array($session->getType(), [DocumentSession::TYPE_VIEW, DocumentSession::TYPE_EDIT], true)
			|| ($session->isVersion() && !$session->isView())
		)
		{
			throw new ArgumentException('Invalid Vibeoffice document scope.');
		}

		return [
			'OBJECT_ID' => $objectId,
			'VERSION_ID' => (int)$session->getVersionId(),
			'SESSION_TYPE' => $session->getType(),
			'EXTERNAL_LINK_ID' => $externalLinkId,
		];
	}

	public static function create(array $scope, string $contentGeneration): string
	{
		return 'vo1_' . hash('sha256', self::scopeIdentity($scope) . ':' . $contentGeneration);
	}

	public static function scopeIdentity(array $scope): string
	{
		return implode(':', [
			(int)$scope['OBJECT_ID'],
			(int)$scope['VERSION_ID'],
			(int)$scope['SESSION_TYPE'],
			(int)$scope['EXTERNAL_LINK_ID'],
		]);
	}

	public static function contentGeneration(array $scope, int $fileId, int $contentVersion): string
	{
		return hash('sha256', implode(':', [
			(int)$scope['VERSION_ID'],
			$fileId,
			(int)$scope['VERSION_ID'] > 0 ? 0 : $contentVersion,
		]));
	}
}
