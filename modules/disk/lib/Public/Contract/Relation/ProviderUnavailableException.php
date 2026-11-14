<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Contract\Relation;

use Bitrix\Main\SystemException;

/**
 * The module of the provider cannot answer for now; the page gets MODULE_UNAVAILABLE.
 *
 * The cause is passed as previous. The inherited constructor takes it fifth, after $file and $line, so a
 * wrapping throw either fills them in or names the argument:
 * `throw new ProviderUnavailableException($message, 0, __FILE__, __LINE__, $cause);`
 */
class ProviderUnavailableException extends SystemException
{
}
