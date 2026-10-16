<?php

namespace App\Enums;

/**
 * Permissions nommées (cahier des charges §26-27, SCRUM-58).
 * Qui a quoi : TeamRole::permissions(), seul endroit où la matrice est définie.
 */
enum Permission: string
{
    case TargetView = 'target.view';
    case TargetCreate = 'target.create';
    case TargetUpdate = 'target.update';
    case TargetDelete = 'target.delete';
    case AlertView = 'alert.view';
    case AlertAcknowledge = 'alert.acknowledge';
    case UserView = 'user.view';
    case UserCreate = 'user.create';
    case UserUpdate = 'user.update';
    case UserDelete = 'user.delete';
    case DashboardView = 'dashboard.view';
    case ConfigurationManage = 'configuration.manage';
    case AuditView = 'audit.view';
}
