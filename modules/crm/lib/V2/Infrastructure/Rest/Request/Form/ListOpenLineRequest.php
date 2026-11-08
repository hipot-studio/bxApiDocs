<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Form;

/**
 * The chats of the portal are always read against one CRM form: the state of a chat is answered for
 * the form that asked about it.
 *
 * The whole contract comes from {@see AbstractFormScopedListRequest}.
 */
final class ListOpenLineRequest extends AbstractFormScopedListRequest
{
}
