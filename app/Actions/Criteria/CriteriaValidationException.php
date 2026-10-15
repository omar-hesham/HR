<?php

namespace App\Actions\Criteria;

use App\Domain\Criteria\CriteriaValidationResult;
use RuntimeException;

final class CriteriaValidationException extends RuntimeException
{
    public function __construct(public readonly CriteriaValidationResult $result)
    {
        parent::__construct(implode(' ', $result->keys()));
    }

    /**
     * @return list<string>
     */
    public function messages(): array
    {
        $messages = [];

        foreach ($this->result->messages() as $attributeMessages) {
            foreach ($attributeMessages as $message) {
                $messages[] = $message;
            }
        }

        return array_values(array_unique($messages));
    }
}
