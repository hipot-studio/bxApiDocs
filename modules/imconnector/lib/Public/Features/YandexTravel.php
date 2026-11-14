<?php

declare(strict_types=1);

namespace Bitrix\ImConnector\Public\Features;

use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * Shows the Yandex.Travel connector in the contact center. Off by default: the channel is rolled out
 * portal by portal while the live contour is being verified.
 */
final class YandexTravel extends AbstractFlag
{
}
