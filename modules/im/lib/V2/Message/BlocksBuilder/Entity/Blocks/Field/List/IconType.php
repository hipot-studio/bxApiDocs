<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\List;

enum IconType: string
{
	case Bullet = 'bullet';
	case ChevronToTheLeft = 'chevron_to_the_left';
	case ChevronToTheRight = 'chevron_to_the_right';
	case ChevronUp = 'chevron_up';
	case ChevronDown = 'chevron_down';
	case ArrowToTheLeft = 'arrow_to_the_left';
	case ArrowToTheRight = 'arrow_to_the_right';
	case ArrowTop = 'arrow_top';
	case ArrowDown = 'arrow_down';
	case CircleCheck = 'circle_check';
	case AlertAccent = 'alert_accent';
	case Clock = 'clock';
	case Search = 'search';
	case Fire = 'fire';
	case Task = 'task';
	case Crm = 'crm';
	case File = 'file';
	case Mail = 'mail';
	case Message = 'message';
	case PhoneUp = 'phone_up';
	case CalendarWithSlots = 'calendar_with_slots';
	case Attach = 'attach';
	case Location = 'location';
	case Person = 'person';
	case GraduationCap = 'graduation_cap';
	case ShoppingCart = 'shopping_cart';
	case Wallet = 'wallet';
	case Collab = 'collab';
	case DeveloperResources = 'developer_resources';
	case Services = 'services';
	case IdeaLamp = 'idea_lamp';
	case Gift = 'gift';
	case Cloud = 'cloud';
	case Notification = 'notification';
}
