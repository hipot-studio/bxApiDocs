<?php

namespace Bitrix\Crm\RepeatSale\Service\Handler;

enum AiScreeningOpinion: int
{
	case isRepeatSalePossible = 1;
	case isRepeatSaleNotPossible = 2;
	// a stop factor is in force: the client asked not to be contacted, left for a competitor or is
	// aggressive. Collectors take rows by strict equality to their own opinion, so nobody selects it.
	case isRepeatSaleStopped = 3;
}
