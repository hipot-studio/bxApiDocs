<?php

namespace Bitrix\Bizproc\Activity\Enum;

enum ActivityPortType: string
{
	case Input = 'input';
	case Output = 'output';
	case Aux = 'aux';
	case TopAux = 'topAux';
}
