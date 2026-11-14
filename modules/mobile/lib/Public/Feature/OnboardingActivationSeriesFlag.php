<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Public\Feature;

use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * Switches the RU onboarding pushes to the activation series driven by the campaign
 * document. While it is off, RU keeps the legacy "day -> push type" map.
 */
final class OnboardingActivationSeriesFlag extends AbstractFlag
{
}
