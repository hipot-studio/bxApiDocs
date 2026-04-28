<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Promo;

enum PromoType: string
{
	case Slider = 'slider';
	case SliderWithPopup = 'sliderWithPopup';
	case Form = 'form';
	case Boost = 'boost';
}
