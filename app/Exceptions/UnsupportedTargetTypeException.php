<?php

namespace App\Exceptions;

use App\Enums\TargetType;
use LogicException;

/** Type de target sans provisioner Zabbix (config monitoring.provisioners). */
class UnsupportedTargetTypeException extends LogicException
{
    public static function for(TargetType $type): self
    {
        return new self("No Zabbix provisioner for the target type \"{$type->value}\".");
    }
}
