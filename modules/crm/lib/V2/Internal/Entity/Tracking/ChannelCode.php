<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Tracking;

/**
 * The closed list of the channels a trace can come through, as legacy stores them.
 *
 * A new channel of legacy has to be added here: the list is compared with legacy by a test.
 *
 * @internal
 */
enum ChannelCode: string
{
	case Mail = 'mail';
	case Call = 'call';
	case Imol = 'imol';
	case Site = 'site';
	case Site24 = 'site24';
	case Shop24 = 'shop24';
	case CrmShop = 'crm-shop';
	case SiteDomain = 'site-domain';
	case Button = 'button';
	case Form = 'form';
	case Callback = 'callback';
	case Rest = 'rest';
	case Order = 'order';
	case SalesCenter = 'sales-center';
	case FbLeadAds = 'fb-lead-ads';
	case VkLeadAds = 'vk-lead-ads';
}
