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

	public static function matchesStableEditScope(array $state, string $hostDocKey): bool
	{
		$generation = $state['CONTENT_GENERATION'] ?? null;
		$scopeVersion = (int)($state['EDIT_SCOPE_VERSION'] ?? 0);

		return in_array($scopeVersion, [0, 1], true)
			&& ($scopeVersion === 0 || (int)($state['EXTERNAL_LINK_ID'] ?? -1) === 0)
			&& ($state['HOST_DOC_KEY'] ?? null) === $hostDocKey
			&& ($state['KEY_MODE'] ?? null) === self::MODE_STABLE
			&& (int)($state['OBJECT_ID'] ?? 0) > 0
			&& (int)($state['VERSION_ID'] ?? 0) === 0
			&& (int)($state['SESSION_TYPE'] ?? 0) === DocumentSession::TYPE_EDIT
			&& (int)($state['EXTERNAL_LINK_ID'] ?? -1) >= 0
			&& is_string($generation) && preg_match('/^[a-f0-9]{64}$/D', $generation) === 1
			&& self::create($state, $generation) === $hostDocKey;
	}

	public static function matchesSharedEditScope(array $state, string $hostDocKey): bool
	{
		return (int)($state['EDIT_SCOPE_VERSION'] ?? 0) === 1
			&& ($state['HOST_DOC_KEY'] ?? null) === $hostDocKey
			&& (int)($state['OBJECT_ID'] ?? 0) > 0
			&& (int)($state['VERSION_ID'] ?? -1) === 0
			&& (int)($state['SESSION_TYPE'] ?? 0) === DocumentSession::TYPE_EDIT
			&& (int)($state['EXTERNAL_LINK_ID'] ?? -1) === 0
			&& preg_match('/^[a-f0-9]{64}$/D', $state['CONTENT_GENERATION'] ?? '') === 1
			&& (($state['KEY_MODE'] ?? null) === self::MODE_LEGACY
				? !self::isStable($hostDocKey) : self::matchesStableEditScope($state, $hostDocKey));
	}

	public static function createScope(DocumentSession $session, File $freshFile, int $editScopeVersion = 0): array
	{
		$objectId = (int)$freshFile->getRealObjectId();
		$context = $session->getContext();
		$externalLinkId = (int)$session->getExternalLinkId();
		if (
			!in_array($editScopeVersion, [0, 1], true)
			|| $session->getService() !== DocumentService::Vibeoffice
			|| $objectId <= 0
			|| $objectId !== (int)$session->getObjectId()
			|| !$context
			|| $context->getObjectId() !== $objectId
			|| (int)$context->getExternalLinkId() !== $externalLinkId
			|| !in_array($session->getType(), [DocumentSession::TYPE_VIEW, DocumentSession::TYPE_EDIT], true)
			|| ($session->isVersion() && !$session->isView())
			|| ($editScopeVersion === 1 && (!$session->isEdit() || (int)$session->getVersionId() !== 0))
		)
		{
			throw new ArgumentException('Invalid Vibeoffice document scope.');
		}

		$scope = [
			'OBJECT_ID' => $objectId,
			'VERSION_ID' => (int)$session->getVersionId(),
			'SESSION_TYPE' => $session->getType(),
			'EXTERNAL_LINK_ID' => $externalLinkId,
		];
		if ($editScopeVersion === 1)
		{
			$scope['EXTERNAL_LINK_ID'] = 0;
			$scope['EDIT_SCOPE_VERSION'] = 1;
		}

		return $scope;
	}

	public static function create(array $scope, string $contentGeneration): string
	{
		return 'vo1_' . hash('sha256', self::scopeIdentity($scope) . ':' . $contentGeneration);
	}

	public static function scopeIdentity(array $scope): string
	{
		$scopeVersion = (int)($scope['EDIT_SCOPE_VERSION'] ?? 0);
		if (!in_array($scopeVersion, [0, 1], true))
		{
			throw new ArgumentException('Unknown Vibeoffice edit scope version.');
		}
		$identity = implode(':', [
			(int)$scope['OBJECT_ID'],
			(int)$scope['VERSION_ID'],
			(int)$scope['SESSION_TYPE'],
			(int)$scope['EXTERNAL_LINK_ID'],
		]);

		return $scopeVersion === 1 ? 'shared-edit-v1:' . $identity : $identity;
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
