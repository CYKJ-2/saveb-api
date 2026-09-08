<?php

return [
    'seed_admin_password' => env('RBAC_SEED_ADMIN_PASSWORD', env('APP_ENV') === 'local' ? '123456' : null),
];
