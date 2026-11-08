<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Admission;

interface AdmissionGateInterface
{
	public function ensureAdmitted(): void;
}
