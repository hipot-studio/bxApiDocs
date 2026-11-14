<?php

namespace Bitrix\Mobile\Internal\Onboarding\Dto;

use Bitrix\Mobile\Internal\Onboarding\Config\FinalPushConfig;
use Bitrix\Mobile\Internal\Onboarding\PushType;

/**
 * A final push that falls due today, together with the document values it was planned
 * from: the `finals` section is then parsed once per pass instead of once per question
 * asked about the same final.
 */
final readonly class DueFinalPush
{
	public function __construct(
		public PushType $pushType,
		public FinalPushConfig $config,
	)
	{}
}
