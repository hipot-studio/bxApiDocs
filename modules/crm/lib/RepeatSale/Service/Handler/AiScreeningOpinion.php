<?php

namespace Bitrix\Crm\RepeatSale\Service\Handler;

enum AiScreeningOpinion: int
{
	case isRepeatSalePossible = 1;
	case isRepeatSaleNotPossible = 2;
}
