<?php

namespace App;

enum PermissionName: string
{
    case ViewClassifications = 'classifications.view';
    case CreateClassifications = 'classifications.create';
    case UpdateClassifications = 'classifications.update';
    case DeleteClassifications = 'classifications.delete';
}
