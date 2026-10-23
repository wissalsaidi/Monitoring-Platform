<?php

namespace App\Monitoring;

use App\Enums\TargetType;
use App\Exceptions\UnsupportedTargetTypeException;
use Illuminate\Contracts\Container\Container;

/**
 * Type de target → provisioner Zabbix (config monitoring.provisioners). Un type sans provisioner
 * n'est pas proposé dans le formulaire et ne peut pas être créé.
 */
class ProvisionerRegistry
{
    /** @var array<string, TargetProvisioner> */
    private array $resolved = [];

    public function __construct(private readonly Container $container) {}

    /** @throws UnsupportedTargetTypeException */
    public function for(TargetType $type): TargetProvisioner
    {
        $class = config("monitoring.provisioners.{$type->value}") ?? throw UnsupportedTargetTypeException::for($type);

        return $this->resolved[$type->value] ??= $this->container->make($class);
    }

    public function supports(TargetType $type): bool
    {
        return config("monitoring.provisioners.{$type->value}") !== null;
    }

    /** @return list<TargetType> types supervisables, dans l'ordre de l'enum */
    public function supportedTypes(): array
    {
        return array_values(array_filter(TargetType::cases(), fn (TargetType $type) => $this->supports($type)));
    }
}
