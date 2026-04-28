<?php

namespace Bitrix\Im\V2\Entity\User\Field;

use Bitrix\Im\V2\Integration\AI\CopilotNameResolver;

final class NameResolver
{
	/** @var NameResolverInterface[] */
	private array $resolvers;
	private int $userId;

	public function __construct(int $userId)
	{
		$this->userId = $userId;
		$this->resolvers = [
			CopilotNameResolver::getInstance(),
		];
	}

	public function getName(): ?string
	{
		foreach ($this->resolvers as $resolver)
		{
			$name = $resolver->resolveName($this->userId);

			if ($name !== null)
			{
				return $name;
			}
		}

		return null;
	}

	public function getFirstName(): ?string
	{
		foreach ($this->resolvers as $resolver)
		{
			$firstName = $resolver->resolveFirstName($this->userId);
			if ($firstName !== null)
			{
				return $firstName;
			}
		}

		return null;
	}
}
