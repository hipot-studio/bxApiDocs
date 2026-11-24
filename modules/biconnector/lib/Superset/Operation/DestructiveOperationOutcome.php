<?php

namespace Bitrix\BIConnector\Superset\Operation;

enum DestructiveOperationOutcome: string
{
	case Success = 'SUCCESS';
	case FinalRefusal = 'FINAL_REFUSAL';
	case Undetermined = 'UNDETERMINED';
	case AwaitingGeneration = 'AWAITING_GENERATION';
}
