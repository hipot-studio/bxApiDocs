<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\OnlyOffice\Editor\ConfigBuilder;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Main\Web\Uri;

final class RecoveryNoticeContext
{
	public static function build(
		?DocumentSession $session,
		array $trustedOpening,
		int $userId,
		?string $safeDestination,
	): ?array
	{
		try
		{
			if ($session === null || $userId <= 0 || $session->getService() !== DocumentService::Vibeoffice)
			{
				return null;
			}
			foreach (['objectId', 'attachedObjectId', 'versionId', 'type', 'editorMode'] as $field)
			{
				if (!isset($trustedOpening[$field]) || !is_int($trustedOpening[$field]))
				{
					return null;
				}
			}
			$context = $session->getContext();
			$externalMode = $trustedOpening['externalLinkMode'] ?? null;
			$attachedId = (int)$context?->getAttachedObjectId();
			$versionId = (int)$session->getVersionId();
			if (!is_bool($externalMode) || $context === null
				|| $trustedOpening['objectId'] <= 0 || $attachedId < 0 || $versionId < 0
				|| $trustedOpening['objectId'] !== $session->getObjectId()
				|| $context->getObjectId() !== $session->getObjectId()
				|| $trustedOpening['attachedObjectId'] !== $attachedId
				|| $trustedOpening['versionId'] !== $versionId
				|| !in_array($trustedOpening['type'], [DocumentSession::TYPE_EDIT, DocumentSession::TYPE_VIEW], true)
				|| ($versionId > 0 && $trustedOpening['type'] !== DocumentSession::TYPE_VIEW)
				|| !in_array($trustedOpening['editorMode'], [ConfigBuilder::VISUAL_MODE_USUAL,
					ConfigBuilder::VISUAL_MODE_COMPACT], true))
			{
				return null;
			}

			$channel = $trustedOpening['channel'] ?? null;
			$linkId = (int)$session->getExternalLinkId();
			if ($linkId !== (int)$context->getExternalLinkId())
			{
				return null;
			}
			if ($channel === 'internal')
			{
				if ($externalMode || $linkId !== 0)
				{
					return null;
				}
			}
			elseif ($channel === 'public' || $channel === 'unified')
			{
				if ($channel === 'unified')
				{
					$file = $trustedOpening['file'] ?? null;
					if (!$file instanceof File || (int)$file->getId() !== $session->getObjectId())
					{
						return null;
					}
				}
				if ($externalMode)
				{
					$link = $trustedOpening['externalLink'] ?? null;
					if (!$link instanceof ExternalLink || $linkId <= 0 || (int)$link->getId() !== $linkId
						|| (int)$link->getVersionId() !== $versionId
						|| ($channel === 'public' && $attachedId !== 0))
					{
						return null;
					}
				}
				elseif ($channel === 'public' || $linkId !== 0)
				{
					return null;
				}
			}
			else
			{
				return null;
			}

			$destination = self::normalizeDestination($safeDestination);
			if ($destination === null)
			{
				return null;
			}

			return [
				'userId' => $userId,
				'objectId' => $session->getObjectId(),
				'attachedId' => $attachedId,
				'externalLinkMode' => $externalMode,
				'destination' => $destination,
			];
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	private static function normalizeDestination(?string $destination): ?string
	{
		if ($destination === null || preg_match('#^/(?!/)[^\\\\\x00-\x20\x7F]*$#D', $destination) !== 1)
		{
			return null;
		}
		$uri = new Uri($destination);
		$query = [];
		parse_str($uri->getQuery(), $query);
		foreach (array_keys($query) as $name)
		{
			if (preg_match('/^(vo_sess|sessid|token|accessToken|documentSessionHash|openingContext|externalLinkContext)$/iD',
				(string)$name) === 1)
			{
				return null;
			}
		}
		if (array_key_exists('disk_vo_reopen', $query))
		{
			$uri->deleteParams(['disk_vo_reopen'], true);
		}
		if (str_starts_with($uri->getFragment(), 'disk-vo-reopen='))
		{
			$uri = $uri->withFragment('');
		}

		return $uri->getUri();
	}
}
