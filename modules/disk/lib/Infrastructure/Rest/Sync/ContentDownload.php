<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\Sync;

use Bitrix\Disk\Internal\Service\Sync\ContentLinkProvider;
use Bitrix\Disk\Internal\Service\Sync\SyncFeatureOption;
use Bitrix\Disk\Rest\Service\File;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Rest\AccessException;
use Bitrix\Rest\RestException;

/**
 * The far end of a link handed out by disk.sync.content.get.
 *
 * It answers in the classic contour and not in V3 because that is where the download transport of the
 * platform lives, and it is published in the global scope because a link minted inside a V3 method can
 * be signed for no other one - see {@see ContentLinkProvider::DOWNLOAD_METHOD}. A method of the global
 * scope is a method every caller may reach, so nothing here is authorised by the scope: the grant in
 * the link says which object was opened and to which pair of a user and an application, both of them
 * read off the credentials of the request rather than off what it carries, and the rights of Disk are
 * asked again below.
 *
 * The bytes are not served here. They are served by the download of the classic REST contour of Disk,
 * the same one disk.file.download has always used, reached with the identifier the grant carries - so
 * this contour adds a way in and no second way of reading a file.
 */
final class ContentDownload
{
	/**
	 * The switch of the contour stands in front of this end of the link as it stands in front of every
	 * method of it. Otherwise a portal that turned the contour off would go on serving files through
	 * links minted before it did, for as long as the last of them lives - and a switch with an hour of
	 * tail behind it is a switch nobody can rely on.
	 *
	 * The refusal is published under the code of the contour but in the envelope of the classic
	 * contour, which is the one this method answers in.
	 *
	 * @param array<string, mixed> $query the grant, as {@see \CRestServer} unpacked it from the token
	 * @throws RestException the contour is switched off on this portal
	 * @throws AccessException the grant is absent, forged, expired, or belongs to another caller
	 */
	public static function download(array $query, mixed $scope, \CRestServer $server): mixed
	{
		if (!(new SyncFeatureOption())->isEnabled())
		{
			throw new RestException(
				'The synchronisation contour is not available on this portal.',
				'FEATURE_NOT_SUPPORTED',
				\CRestServer::STATUS_WRONG_REQUEST,
			);
		}

		$userId = (int)CurrentUser::get()->getId();

		$objectId = (new ContentLinkProvider())->objectIdOfGrant($query, $userId, $server);
		if ($objectId === null)
		{
			throw new AccessException();
		}

		return (new File(\CRestUtil::METHOD_DOWNLOAD, ['id' => $objectId], 0, $server))->processMethodRequest();
	}
}
