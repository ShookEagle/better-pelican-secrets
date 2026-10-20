<?php

namespace ShookEagle\BetterPelicanSecrets\Secrets;

enum SecretOperation: string
{
    case ListSecrets = 'list';
    case Reveal = 'reveal';
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case Bind = 'bind';
    case ManageAccess = 'manage-access';

    public function nativeSubuserName(): string
    {
        return 'secrets.' . $this->value;
    }

    public function nativeRoleName(): string
    {
        return $this->value . ' secrets';
    }
}
