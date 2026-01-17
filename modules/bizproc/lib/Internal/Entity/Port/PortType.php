<?php

namespace Bitrix\Bizproc\Internal\Entity\Port;

enum PortType: string
{
	case Input = 'i';
	case Output = 'o';
	case Aux = 'a';
	case Top = 't';
}
