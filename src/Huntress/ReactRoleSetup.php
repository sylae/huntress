<?php
/*
 * Copyright (c) 2022 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;

class ReactRoleSetup
{
    public int $messageID;
    public int $roleID;
    public int|string $react;
    public string $permission;
    public bool $permissionDefault = false;
}