<?php

arch('the messaging SDK does not depend on any domain')
    ->expect('App\Infrastructure')
    ->not->toUse('App\Domains');
