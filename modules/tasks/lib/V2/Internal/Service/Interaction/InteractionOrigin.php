<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Interaction;

/**
 * Discriminator between an interactive user action and a programmatic call.
 * Only `Ui` originated actions are recorded by the {@see InteractionRecorder};
 * `System` is the default for every other path (REST apps, bizproc, agents,
 * legacy CTasks).
 */
enum InteractionOrigin: string
{
	case Ui = 'ui';
	case System = 'system';
}
