<?php

namespace Bitrix\BizprocDesigner\Internal\Enum;

enum PortDirection: string
{
	case Input = 'input';
	case Output = 'output';
	case Aux = 'aux';
	case TopAux = 'topAux';
}
