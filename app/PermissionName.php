<?php

namespace App;

enum PermissionName: string
{
    case ViewClassifications = 'classifications.view';
    case CreateClassifications = 'classifications.create';
    case UpdateClassifications = 'classifications.update';
    case DeleteClassifications = 'classifications.delete';

    case ViewProducts = 'products.view';
    case CreateProducts = 'products.create';
    case UpdateProducts = 'products.update';
    case DeleteProducts = 'products.delete';

    // Cada panel exige su permiso: el administrador los tiene todos; el cliente, solo el suyo.
    case ViewAdminDashboard = 'dashboard.admin';
    case ViewClientDashboard = 'dashboard.client';

    case ViewUsers = 'users.view';
    case CreateUsers = 'users.create';
    case UpdateUsers = 'users.update';
    case DeactivateUsers = 'users.deactivate';
}
