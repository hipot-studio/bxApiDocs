<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Public\Features;

use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * The library of the reusable assemblies of the web designer of the business processes.
 *
 * Closes the whole surface of the feature - the entry points of the client and every action of its
 * controller alike - so a portal it is off on refuses the actions on the server and does not merely hide
 * the buttons (DTO-04). The client learns about it by the `componentLibrary` code of the feature registry
 * of the editor, which asks this flag {@see \Bitrix\BizprocDesigner\Internal\Config\Feature}.
 *
 * Off by default, as an unreleased feature should be: the flag is turned on per portal for the rollout and,
 * when the feature ships to the box, `enabledByDefault()` returns true before the checks are removed from
 * the code at all.
 */
final class ComponentLibrary extends AbstractFlag
{
}
