<?php

namespace App;

enum UserRole: string
{
    case Player = 'player';
    case Admin = 'admin';
}
