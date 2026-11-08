<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync;

use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * Where the bytes of one file are to be fetched from, and until when that stays true.
 *
 * The contour hands over a link and never a stream: the bytes are served by the download mechanism
 * Disk already has, and the only thing this method adds to it is a grant the caller may present
 * instead of a session.
 *
 * The expiry is answered rather than left to be discovered, because a client fetches files in bursts
 * and plans them: knowing when the link stops working is what lets it ask for a fresh one before a
 * long queue reaches an expired one, instead of reading the refusal of a download as "the file is
 * gone" and dropping it from its memory.
 *
 * Property order is the order of the published answer.
 */
final class ContentLinkDto extends Dto
{
	public string $url;

	public DateTime $expiresAt;
}
