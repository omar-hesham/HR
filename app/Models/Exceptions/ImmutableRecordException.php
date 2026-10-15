<?php

namespace App\Models\Exceptions;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ImmutableRecordException extends LogicException
{
    public static function forModel(Model $model): self
    {
        return new self(sprintf('%s #%s is immutable.', $model::class, (string) $model->getKey()));
    }
}
