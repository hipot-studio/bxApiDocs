<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Form;

/**
 * The widgets of the portal are always read against one CRM form: a page of widgets means nothing
 * without the form it is projected against.
 *
 * The whole contract comes from {@see AbstractFormScopedListRequest}.
 */
final class ListSiteButtonRequest extends AbstractFormScopedListRequest
{
}
