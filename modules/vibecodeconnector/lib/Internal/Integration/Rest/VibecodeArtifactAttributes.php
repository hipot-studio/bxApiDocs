<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Rest;

final class VibecodeArtifactAttributes
{
	public const DEVELOPER_KEY_CODE = 'developer_key';

	public const VIBECODE_OWNED = ['vibecodeconnector' => 'Y'];
	public const ARTIFACT = ['vibe24' => 'Y', ...self::VIBECODE_OWNED];
	public const DEVELOPER_KEY = [...self::ARTIFACT, self::DEVELOPER_KEY_CODE => 'Y'];
}
