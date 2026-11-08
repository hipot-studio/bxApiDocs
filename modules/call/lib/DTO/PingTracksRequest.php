<?php

namespace Bitrix\Call\DTO;

/**
 * Batched fire-and-forget ping from callcontroller for arbitrary tracks of one
 * account. The hit re-runs scheduled agents; the portal pushes back the state of
 * tracks that won't report otherwise. Tracks may belong to different calls —
 * externalTrackId is globally unique within an account on the KK side.
 *
 * @internal
 */
class PingTracksRequest extends Hydrator
{
	/** @var int[] */
	public array $externalTrackIds = [];
	public string $requestId = '';
}
