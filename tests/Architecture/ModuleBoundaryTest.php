<?php

// Modules communicate through domain events or App\Shared\Contracts
// interfaces, never by depending on another module's classes directly
// (docs/firstmarket_Developer_Guidelines.md golden rules). Shared code must
// stay dependency-free of feature modules, since modules depend on Shared,
// not the other way around.

arch('Shared code does not depend on any feature module')
    ->expect('App\Shared')
    ->not->toUse('App\Modules');

arch('the Auth module does not depend on the Admin module directly')
    ->expect('App\Modules\Auth')
    ->not->toUse('App\Modules\Admin');

arch('the Admin module does not depend on the Auth module\'s internals directly')
    ->expect('App\Modules\Admin')
    ->not->toUse('App\Modules\Auth');
