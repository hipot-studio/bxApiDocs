<?php
declare(strict_types=1);

namespace Bitrix\Im\V2;

class AccessCheckResult extends Result
{
	public function getError(): ?AccessError
	{
		foreach ($this->getErrors() as $error)
		{
			if ($error instanceof AccessError)
			{
				return $error;
			}
		}

		return null;
	}

	public static function allowed(): self
	{
		return new self();
	}

	public static function denied(AccessError $error): self
	{
		$result = new self();
		$result->addError($error);

		return $result;
	}

	public static function fromResult(Result $source): self
	{
		if ($source->isSuccess())
		{
			return self::allowed();
		}

		$result = new self();
		foreach ($source->getErrors() as $error)
		{
			$result->addError(
				$error instanceof AccessError
					? $error
					: AccessError::fromError($error, revokeAccess: true),
			);
		}

		return $result;
	}
}
