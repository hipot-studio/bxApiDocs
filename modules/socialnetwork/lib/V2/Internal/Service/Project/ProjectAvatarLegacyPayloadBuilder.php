<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Project;

use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Avatar;

class ProjectAvatarLegacyPayloadBuilder
{
	/**
	 * empty payload = explicit delete;
	 * round-trip payload = no-op without IMAGE_ID comparison;
	 * encodedFile === '' is treated as empty payload.
	 *
	 * @return array{avatarId?: string}
	 */
	public function buildForAdd(?Avatar $avatar): array
	{
		if ($avatar === null || $avatar->isEmptyPayload() || $avatar->isRoundTripPayload())
		{
			return [];
		}

		return ['avatarId' => $avatar->encodedFile];
	}

	/**
	 * empty payload = explicit delete;
	 * round-trip payload = no-op without IMAGE_ID comparison;
	 * encodedFile === '' is treated as empty payload.
	 *
	 * @return array{avatarId?: string}
	 */
	public function buildForUpdate(?Avatar $avatar): array
	{
		if ($avatar === null)
		{
			return [];
		}

		if ($avatar->isEmptyPayload())
		{
			return ['avatarId' => ''];
		}

		if ($avatar->isRoundTripPayload())
		{
			return [];
		}

		return ['avatarId' => $avatar->encodedFile];
	}
}
