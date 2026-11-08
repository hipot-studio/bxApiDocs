<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Controller\Vibeoffice;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\OnlyOffice\Editor\ConfigBuilder;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\Main\Security\Sign\Signer;
use Bitrix\Main\Web\Json;

final class RestartTarget
{
	private const SIGNATURE_SALT = 'disk_vibeoffice_opening_v1';

	public static function build(DocumentSession $session, array $trustedOpening): ?string
	{
		if (!self::matches($session, $trustedOpening))
		{
			return null;
		}

		$channel = $trustedOpening['channel'];
		$isView = $trustedOpening['type'] === DocumentSession::TYPE_VIEW;
		$versionId = $trustedOpening['versionId'];
		$attachedId = $trustedOpening['attachedObjectId'];
		$mode = $trustedOpening['editorMode'];

		if ($channel === 'internal')
		{
			if ((int)$session->getExternalLinkId() > 0)
			{
				return null;
			}

			$url = (string)(new Vibeoffice())->getActionUri(
				$isView ? 'loadDocumentViewer' : 'loadDocumentEditor',
				[
					'objectId' => $trustedOpening['objectId'],
					'attachedObjectId' => $attachedId ?: null,
					'versionId' => $versionId ?: null,
					'editorMode' => $mode,
				],
			);
		}
		elseif ($mode === ConfigBuilder::VISUAL_MODE_COMPACT)
		{
			return null;
		}
		elseif ($channel === 'public')
		{
			$link = $trustedOpening['externalLink'] ?? null;
			if (
				!$link instanceof ExternalLink
				|| (int)$session->getExternalLinkId() !== (int)$link->getId()
				|| (int)$link->getVersionId() !== $versionId
				|| $attachedId > 0
			)
			{
				return null;
			}

			$url = \Bitrix\Disk\Driver::getInstance()->getUrlManager()->getUrlExternalLink([
				'hash' => $link->getHash(),
				'action' => $isView ? 'default' : 'goToEdit',
			]);
		}
		elseif ($channel === 'unified')
		{
			$file = $trustedOpening['file'] ?? null;
			if (!$file instanceof File || (int)$file->getId() !== $trustedOpening['objectId'])
			{
				return null;
			}

			$options = $trustedOpening['unifiedOptions'] ?? [];
			$options['attachedId'] = $attachedId ?: null;
			$options['versionId'] = $versionId ?: null;
			$options['absolute'] = false;
			$options['exactMode'] = $isView;
			$options['noRedirect'] = $isView;
			$urlManager = \Bitrix\Disk\Driver::getInstance()->getUrlManager();
			$url = $isView
				? $urlManager->getUnifiedLink($file, $options)
				: $urlManager->getUnifiedEditLink($file, $options);
		}
		else
		{
			return null;
		}

		return str_starts_with($url, '/') && !str_starts_with($url, '//')
			&& !str_contains($url, '\\') && !preg_match('/[\x00-\x20]/', $url)
			? $url
			: null;
	}

	public static function sign(array $trustedOpening, int $userId): string
	{
		return (new Signer())->sign(
			base64_encode(Json::encode(['userId' => $userId, 'opening' => $trustedOpening])),
			self::SIGNATURE_SALT,
		);
	}

	public static function restore(string $token, int $userId, DocumentSession $session): array
	{
		try
		{
			$decoded = base64_decode((new Signer())->unsign($token, self::SIGNATURE_SALT), true);
			$payload = $decoded === false ? null : Json::decode($decoded);
		}
		catch (BadSignatureException|ArgumentException)
		{
			return [];
		}

		$opening = $payload['opening'] ?? null;
		if (
			($payload['userId'] ?? null) !== $userId
			|| !is_array($opening)
			|| ($opening['channel'] ?? null) !== 'internal'
			|| !self::matches($session, $opening)
		)
		{
			return [];
		}

		return $opening;
	}

	private static function matches(DocumentSession $session, array $opening): bool
	{
		foreach (['objectId', 'attachedObjectId', 'versionId', 'type', 'editorMode'] as $key)
		{
			if (!isset($opening[$key]) || !is_int($opening[$key]))
			{
				return false;
			}
		}

		return isset($opening['channel'])
			&& $opening['objectId'] > 0
			&& $opening['objectId'] === (int)$session->getObjectId()
			&& $opening['attachedObjectId'] === (int)$session->getContext()?->getAttachedObjectId()
			&& $opening['versionId'] === (int)$session->getVersionId()
			&& in_array($opening['type'], [DocumentSession::TYPE_VIEW, DocumentSession::TYPE_EDIT], true)
			&& ($opening['versionId'] === 0 || $opening['type'] === DocumentSession::TYPE_VIEW)
			&& in_array($opening['editorMode'], [ConfigBuilder::VISUAL_MODE_USUAL, ConfigBuilder::VISUAL_MODE_COMPACT], true);
	}
}
