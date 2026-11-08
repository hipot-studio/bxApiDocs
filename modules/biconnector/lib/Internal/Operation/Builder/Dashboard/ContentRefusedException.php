<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Dashboard;

/**
 * A chart or a filter of a content replace the layout and filter builders refuse, with their description of it.
 * Never leaves SetContentOperation: it becomes an InvalidArgument refusal there.
 */
final class ContentRefusedException extends \RuntimeException
{
}
