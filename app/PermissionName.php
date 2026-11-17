<?php

namespace App;

enum PermissionName: string
{
    case ViewClassifications = 'classifications.view';
    case CreateClassifications = 'classifications.create';
    case UpdateClassifications = 'classifications.update';
    case DeleteClassifications = 'classifications.delete';

    case ViewUsers = 'users.view';
    case CreateUsers = 'users.create';
    case UpdateUsers = 'users.update';
    case DeactivateUsers = 'users.deactivate';
}
